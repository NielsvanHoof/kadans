<?php

declare(strict_types=1);

namespace App\Pipelines\Transitions;

use App\Pipelines\Enums\PipelineStatus;
use App\Pipelines\Enums\PipelineStepStatus;
use App\Pipelines\Models\Pipeline;
use App\Pipelines\Models\PipelineStep;
use Illuminate\Validation\ValidationException;

use function in_array;

/**
 * Guarded lifecycle transitions for a pipeline as a whole.
 *
 * A pipeline may be retried from failed or stuck states, while completed and
 * cancelled pipelines remain closed. Step-level transitions that also touch
 * their parent pipeline live in PipelineStepTransitions instead.
 *
 * Every write here is quiet (saveQuietly): Pipeline does not use LogsActivity,
 * so these writes were never part of the activity log to begin with, and the
 * volume is deliberate — steps and heartbeats change far more often than a
 * user would want to see in an audit trail. Business-meaningful events
 * (retried, failed) are logged explicitly via LogAuditActivityAction by the
 * calling action instead of relying on model events here.
 */
final class PipelineTransitions
{
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

    public function fail(
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

    public function stuck(
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

    public function heartbeat(Pipeline $pipeline): Pipeline
    {
        if ($pipeline->status->isTerminal()) {
            return $pipeline;
        }

        $pipeline->forceFill(['last_activity_at' => now()])->saveQuietly();

        return $pipeline;
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
