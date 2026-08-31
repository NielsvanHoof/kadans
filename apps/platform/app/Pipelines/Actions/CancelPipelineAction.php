<?php

declare(strict_types=1);

namespace App\Pipelines\Actions;

use App\Accounts\Models\User;
use App\Audit\Actions\LogAuditActivityAction;
use App\Audit\Enums\AuditEvent;
use App\Pipelines\Models\Pipeline;
use App\Pipelines\Support\PipelineRunnerRegistry;
use App\Pipelines\Transitions\PipelineTransitions;
use Illuminate\Support\Facades\DB;

/**
 * Stop a queued or running run at the operator's request.
 *
 * Cancelling has to release the subject as well as close the run. Every
 * processing job claims its subject and then skips silently while that subject
 * looks in flight, so a cancelled run would otherwise leave its document or
 * recording stuck in `processing` with nothing left to move it on. The reset
 * itself is the owning domain's business, so it goes through the same registry
 * StartPipelineAction and RetryPipelineAction use.
 *
 * Unlike a retry, this does not queue anything, and a missing subject does not
 * block it: the run still needs closing either way.
 */
final readonly class CancelPipelineAction
{
    public function __construct(
        private PipelineTransitions $transitions,
        private BroadcastPipelineUpdatedAction $broadcastPipelineUpdated,
        private LogAuditActivityAction $logAuditActivity,
        private PipelineRunnerRegistry $runners,
    ) {}

    public function handle(Pipeline $pipeline, User $user): Pipeline
    {
        $cancelled = DB::transaction(function () use ($pipeline, $user): Pipeline {
            $lockedPipeline = $pipeline->refreshForUpdate();

            $previousStatus = $lockedPipeline->status;
            $previousStage = $lockedPipeline->current_stage;

            $this->transitions->cancel($lockedPipeline);
            $this->releaseSubject($lockedPipeline);

            $this->logAuditActivity->handle(
                AuditEvent::PipelineCancelled,
                $lockedPipeline,
                [
                    'pipeline_type' => $lockedPipeline->type->value,
                    'previous_status' => $previousStatus->value,
                    'cancelled_at_stage' => $previousStage?->value,
                    'attempt_number' => $lockedPipeline->attempt_number,
                ],
                causer: $user,
            );

            return $lockedPipeline;
        });

        $this->broadcastPipelineUpdated->handle($cancelled->load('steps'));

        return $cancelled;
    }

    /**
     * Hand the subject back to a state where nothing thinks work is in flight.
     * A run whose subject has already been purged has nothing to release.
     */
    private function releaseSubject(Pipeline $pipeline): void
    {
        $runner = $this->runners->for($pipeline->type);

        $pipeline->loadMissing('pipelineable');
        $subject = $pipeline->pipelineable;
        $expected = $runner->subjectClass();

        if ($subject instanceof $expected) {
            $runner->resetSubject($subject);
        }
    }
}
