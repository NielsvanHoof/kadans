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
 * Guarded lifecycle transitions for a single pipeline step and the parent
 * pipeline it belongs to. Steps are advanced in position order.
 *
 * Pipeline-only effects of a step failing or getting stuck are delegated to
 * PipelineTransitions rather than duplicated here.
 *
 * Every write here is quiet (saveQuietly): PipelineStep/Pipeline do not use
 * LogsActivity, so these writes were never part of the activity log to begin
 * with, and the volume is deliberate — steps and heartbeats change far more
 * often than a user would want to see in an audit trail. Business-meaningful
 * events are logged explicitly via LogAuditActivityAction by the calling
 * action instead of relying on model events here.
 */
final readonly class PipelineStepTransitions
{
    public function __construct(private PipelineTransitions $pipelineTransitions) {}

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
        $this->pipelineTransitions->fail($pipeline, $errorMessage, $errorCode);

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
        $this->pipelineTransitions->stuck($pipeline, $errorMessage, $errorCode);

        return $step;
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

    private function ensureCanRun(Pipeline $pipeline): void
    {
        if ($pipeline->status->isSettled()) {
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
}
