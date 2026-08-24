<?php

declare(strict_types=1);

namespace App\Pipelines\Transitions;

use App\Pipelines\Enums\PipelineStatus;
use App\Pipelines\Enums\PipelineStepStatus;
use App\Pipelines\Models\Pipeline;
use App\Pipelines\Models\PipelineStep;
use App\Pipelines\Support\PipelineRetryBackoff;
use Illuminate\Validation\ValidationException;

use function in_array;

/**
 * Guarded lifecycle transitions for a pipeline as a whole.
 *
 * A pipeline may be retried from failed, stuck, or cancelled states, while
 * completed pipelines remain closed. Step-level transitions that also touch
 * their parent pipeline live in PipelineStepTransitions instead.
 *
 * Every write here is quiet (saveQuietly): Pipeline does not use LogsActivity,
 * so these writes were never part of the activity log to begin with, and the
 * volume is deliberate — steps and heartbeats change far more often than a
 * user would want to see in an audit trail. Business-meaningful events
 * (retried, failed) are logged explicitly via LogAuditActivityAction by the
 * calling action instead of relying on model events here.
 */
final readonly class PipelineTransitions
{
    public function __construct(private PipelineRetryBackoff $backoff) {}

    /**
     * Restart a failed, stuck, or cancelled run on the same row, replaying it from its
     * first step. Step `attempts` stays cumulative so the timeline keeps a
     * record of how often each stage has been tried.
     */
    public function retry(Pipeline $pipeline): Pipeline
    {
        if (! $pipeline->status->isRetryable()) {
            throw ValidationException::withMessages([
                'pipeline' => 'Only a failed, stuck, or cancelled pipeline can be retried.',
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

    /**
     * Close a queued or running run at the operator's request.
     *
     * Still-open steps are marked Skipped rather than left Running, so the
     * timeline does not show a stage in progress on a closed run. Skipped is
     * already the vocabulary for "this stage did not happen", which beats
     * adding a step status that means almost the same thing.
     *
     * There is no cancelled_at column, so last_activity_at carries the end
     * time; completed_at stays null because the run did not succeed.
     */
    public function cancel(Pipeline $pipeline): Pipeline
    {
        if ($pipeline->status->isTerminal()) {
            throw ValidationException::withMessages([
                'pipeline' => 'Only a pending or running pipeline can be cancelled.',
            ]);
        }

        $now = now();

        $pipeline->steps()->get()
            ->filter(static fn (PipelineStep $step): bool => in_array(
                $step->status,
                [PipelineStepStatus::Pending, PipelineStepStatus::Running],
                true,
            ))
            ->each(static function (PipelineStep $step) use ($now): void {
                $step->forceFill([
                    'status' => PipelineStepStatus::Skipped,
                    'last_activity_at' => $now,
                    'completed_at' => $now,
                ])->saveQuietly();
            });

        $pipeline->forceFill([
            'status' => PipelineStatus::Cancelled,
            'current_stage' => null,
            'last_activity_at' => $now,
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
        if ($pipeline->status->isTerminal()) {
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

    /**
     * A failed run schedules its own next attempt, unless it has already spent
     * the automatic budget — then next_retry_at stays null and it waits for a
     * human, who can still retry it by hand.
     */
    private function markFailed(Pipeline $pipeline, ?string $message, ?string $errorCode): void
    {
        $pipeline->forceFill([
            'status' => PipelineStatus::Failed,
            'error_code' => $errorCode,
            'error_message' => $message,
            'next_retry_at' => $this->backoff->nextRetryAt($pipeline->attempt_number),
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
