<?php

declare(strict_types=1);

namespace App\Pipelines\Actions;

use App\Accounts\Models\User;
use App\Audit\Actions\LogAuditActivityAction;
use App\Audit\Enums\AuditEvent;
use App\Pipelines\Contracts\PipelineRetryRunner;
use App\Pipelines\Models\Pipeline;
use App\Pipelines\Support\PipelineRetryRunnerRegistry;
use App\Pipelines\Transitions\PipelineTransitions;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Re-queue a failed or stuck run on its existing pipeline row.
 *
 * The subject reset is not cosmetic. Every processing job claims its subject
 * before doing any work and skips silently when it is already `processing` with
 * a provider job id in flight — exactly the state a stuck run leaves behind.
 * Without clearing that, a retry would look successful and then do nothing.
 */
final readonly class RetryPipelineAction
{
    public function __construct(
        private PipelineTransitions $transitions,
        private BroadcastPipelineUpdatedAction $broadcastPipelineUpdated,
        private LogAuditActivityAction $logAuditActivity,
        private PipelineRetryRunnerRegistry $retryRunners,
    ) {}

    /**
     * A null $user means the scheduler retried this, not a person. The audit
     * entry is then causer-less, which is how automatic attempts are told apart
     * from ones somebody asked for.
     */
    public function handle(Pipeline $pipeline, ?User $user = null): Pipeline
    {
        $runner = $this->retryRunners->for($pipeline->type);

        /** @var array{0: Pipeline, 1: Model} $result */
        $result = DB::transaction(function () use ($pipeline, $user, $runner): array {
            $pipelineQuery = Pipeline::query()->whereKey($pipeline->id);
            $pipelineQuery->getQuery()->lockForUpdate();
            $lockedPipeline = $pipelineQuery->firstOrFail();

            $previousStatus = $lockedPipeline->status;
            $subject = $this->resolveSubject($lockedPipeline, $runner);

            $this->transitions->retry($lockedPipeline);
            $runner->resetSubject($subject);

            $this->logAuditActivity->handle(
                AuditEvent::PipelineRetried,
                $lockedPipeline,
                [
                    'pipeline_type' => $lockedPipeline->type->value,
                    'previous_status' => $previousStatus->value,
                    'attempt_number' => $lockedPipeline->attempt_number,
                    'automatic' => ! $user instanceof User,
                ],
                causer: $user,
                includeRequestContext: $user instanceof User,
            );

            return [$lockedPipeline, $subject];
        });

        [$retried, $subject] = $result;

        // Queue only once the reset is durable, so a worker cannot pick the run
        // up while the subject still looks claimed by the previous attempt.
        $runner->dispatch($retried, $subject);
        $this->broadcastPipelineUpdated->handle($retried->load('steps'));

        return $retried;
    }

    /**
     * A run whose subject has gone away can never make progress, so refuse it
     * rather than queueing a job that is guaranteed to no-op.
     */
    private function resolveSubject(Pipeline $pipeline, PipelineRetryRunner $runner): Model
    {
        $pipeline->loadMissing('pipelineable');
        $subject = $pipeline->pipelineable;

        $expected = $runner->subjectClass();

        if (! $subject instanceof $expected) {
            throw ValidationException::withMessages([
                'pipeline' => 'This run can no longer be retried because its subject is unavailable.',
            ]);
        }

        return $subject;
    }
}
