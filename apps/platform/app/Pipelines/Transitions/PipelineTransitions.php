<?php

declare(strict_types=1);

namespace App\Pipelines\Transitions;

use App\Pipelines\Enums\PipelineStatus;
use App\Pipelines\Enums\PipelineStepStatus;
use App\Pipelines\Models\Pipeline;
use App\Pipelines\Models\PipelineStep;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;

use function in_array;

/**
 * Guarded lifecycle transitions for generic application pipelines.
 *
 * A pipeline may be retried from failed or stuck states, while completed and
 * cancelled pipelines remain closed. Steps are advanced in position order.
 */
final class PipelineTransitions
{
    public function start(Pipeline $pipeline, PipelineStep $step): PipelineStep
    {
        if (in_array($step->status, [PipelineStepStatus::Completed, PipelineStepStatus::Skipped], true)) {
            return $step;
        }

        $this->ensureCanRun($pipeline);

        if ($step->status === PipelineStepStatus::Running) {
            return $step;
        }

        $now = now();
        $step->forceFill([
            'status' => PipelineStepStatus::Running,
            'attempts' => $step->attempts + 1,
            'error_code' => null,
            'error_message' => null,
            'started_at' => $now,
            'last_activity_at' => $now,
            'failed_at' => null,
        ])->saveQuietly();

        $pipeline->forceFill([
            'status' => PipelineStatus::Running,
            'current_stage' => $step->key,
            'started_at' => $pipeline->started_at ?? $now,
            'last_activity_at' => $now,
            'error_code' => null,
            'error_message' => null,
            'failed_at' => null,
        ])->saveQuietly();

        return $step;
    }

    public function complete(Pipeline $pipeline, PipelineStep $step): PipelineStep
    {
        if ($step->status === PipelineStepStatus::Completed) {
            return $step;
        }

        $this->ensureStepIsRunning($step, 'Only a running pipeline step can be completed.');

        $now = now();
        $step->forceFill([
            'status' => PipelineStepStatus::Completed,
            'last_activity_at' => $now,
            'completed_at' => $now,
            'failed_at' => null,
        ])->saveQuietly();

        $this->advance($pipeline, $now);

        return $step;
    }

    public function skip(Pipeline $pipeline, PipelineStep $step): PipelineStep
    {
        if (in_array($step->status, [PipelineStepStatus::Completed, PipelineStepStatus::Skipped], true)) {
            return $step;
        }

        if (! in_array($step->status, [PipelineStepStatus::Pending, PipelineStepStatus::Running], true)) {
            throw ValidationException::withMessages([
                'pipeline_step' => 'Only a pending or running pipeline step can be skipped.',
            ]);
        }

        $now = now();
        $step->forceFill([
            'status' => PipelineStepStatus::Skipped,
            'last_activity_at' => $now,
            'completed_at' => $now,
        ])->saveQuietly();

        $this->advance($pipeline, $now);

        return $step;
    }

    public function fail(
        Pipeline $pipeline,
        PipelineStep $step,
        ?string $errorMessage,
        ?string $errorCode,
    ): PipelineStep {
        if (in_array($step->status, [PipelineStepStatus::Completed, PipelineStepStatus::Skipped], true)) {
            return $step;
        }

        $now = now();
        $step->forceFill([
            'status' => PipelineStepStatus::Failed,
            'error_code' => $errorCode,
            'error_message' => $errorMessage,
            'last_activity_at' => $now,
            'failed_at' => $now,
        ])->saveQuietly();
        $this->failPipeline($pipeline, $errorMessage, $errorCode);

        return $step;
    }

    public function stuck(
        Pipeline $pipeline,
        PipelineStep $step,
        ?string $errorMessage,
        ?string $errorCode,
    ): PipelineStep {
        if (in_array($step->status, [PipelineStepStatus::Completed, PipelineStepStatus::Skipped], true)) {
            return $step;
        }

        $now = now();
        $step->forceFill([
            'status' => PipelineStepStatus::Stuck,
            'error_code' => $errorCode,
            'error_message' => $errorMessage,
            'last_activity_at' => $now,
        ])->saveQuietly();
        $this->stuckPipeline($pipeline, $errorMessage, $errorCode);

        return $step;
    }

    /**
     * Restart a failed or stuck run on the same row, replaying it from its
     * first step. Step `attempts` stays cumulative so the timeline keeps a
     * record of how often each stage has been tried.
     */
    public function retry(Pipeline $pipeline): Pipeline
    {
        if (! in_array($pipeline->status, [PipelineStatus::Failed, PipelineStatus::Stuck], true)) {
            throw ValidationException::withMessages([
                'pipeline' => 'Only a failed or stuck pipeline can be retried.',
            ]);
        }

        $pipeline->steps()->get()->each(function (PipelineStep $step): void {
            $step->forceFill([
                'status' => PipelineStepStatus::Pending,
                'error_code' => null,
                'error_message' => null,
                'started_at' => null,
                'last_activity_at' => null,
                'completed_at' => null,
                'failed_at' => null,
            ])->saveQuietly();
        });

        $pipeline->forceFill([
            'status' => PipelineStatus::Pending,
            'current_stage' => null,
            'attempt_number' => $pipeline->attempt_number + 1,
            // A late provider result must not be able to claim this run by its
            // previous job id — see ResolveProviderPipelineForSubjectAction.
            'provider_job_id' => null,
            'error_code' => null,
            'error_message' => null,
            'started_at' => null,
            'last_activity_at' => now(),
            'next_retry_at' => null,
            'completed_at' => null,
            'failed_at' => null,
        ])->saveQuietly();

        return $pipeline;
    }

    public function heartbeat(Pipeline $pipeline, PipelineStep $step): PipelineStep
    {
        if (in_array($step->status, [PipelineStepStatus::Completed, PipelineStepStatus::Skipped], true)) {
            return $step;
        }

        $this->ensureCanRun($pipeline);

        $now = now();
        $step->forceFill(['last_activity_at' => $now])->saveQuietly();
        $pipeline->forceFill(['last_activity_at' => $now])->saveQuietly();

        return $step;
    }

    public function heartbeatPipeline(Pipeline $pipeline): Pipeline
    {
        if ($pipeline->status->isTerminal()) {
            return $pipeline;
        }

        $pipeline->forceFill(['last_activity_at' => now()])->saveQuietly();

        return $pipeline;
    }

    public function failPipeline(
        Pipeline $pipeline,
        ?string $errorMessage,
        ?string $errorCode,
    ): Pipeline {
        if ($pipeline->status === PipelineStatus::Completed) {
            throw ValidationException::withMessages([
                'pipeline' => 'A completed pipeline cannot be failed.',
            ]);
        }

        $this->markFailed($pipeline, $errorMessage, $errorCode);

        return $pipeline;
    }

    public function stuckPipeline(
        Pipeline $pipeline,
        ?string $errorMessage,
        ?string $errorCode,
    ): Pipeline {
        if (in_array($pipeline->status, [PipelineStatus::Completed, PipelineStatus::Cancelled], true)) {
            throw ValidationException::withMessages([
                'pipeline' => 'A closed pipeline cannot be marked stuck.',
            ]);
        }

        $this->markStuck($pipeline, $errorMessage, $errorCode);

        return $pipeline;
    }

    private function ensureCanRun(Pipeline $pipeline): void
    {
        if (in_array($pipeline->status, [PipelineStatus::Completed, PipelineStatus::Cancelled], true)) {
            throw ValidationException::withMessages([
                'pipeline' => 'A completed or cancelled pipeline cannot run another step.',
            ]);
        }
    }

    private function ensureStepIsRunning(PipelineStep $step, string $message): void
    {
        if ($step->status === PipelineStepStatus::Running) {
            return;
        }

        throw ValidationException::withMessages(['pipeline_step' => $message]);
    }

    private function advance(Pipeline $pipeline, CarbonInterface $now): void
    {
        $next = PipelineStep::query()
            ->where('pipeline_id', $pipeline->id)
            ->where('status', PipelineStepStatus::Pending->value)
            ->oldest('position')
            ->first();

        $pipeline->forceFill([
            'status' => $next instanceof PipelineStep ? PipelineStatus::Running : PipelineStatus::Completed,
            'current_stage' => $next?->key,
            'last_activity_at' => $now,
            'completed_at' => $next instanceof PipelineStep ? null : $now,
        ])->saveQuietly();
    }

    private function markFailed(Pipeline $pipeline, ?string $message, ?string $errorCode): void
    {
        $pipeline->forceFill([
            'status' => PipelineStatus::Failed,
            'error_code' => $errorCode,
            'error_message' => $message,
            'failed_at' => now(),
            'last_activity_at' => now(),
        ])->saveQuietly();
    }

    private function markStuck(Pipeline $pipeline, ?string $message, ?string $errorCode): void
    {
        $pipeline->forceFill([
            'status' => PipelineStatus::Stuck,
            'error_code' => $errorCode,
            'error_message' => $message,
            'last_activity_at' => now(),
        ])->saveQuietly();
    }
}
