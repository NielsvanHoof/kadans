<?php

declare(strict_types=1);

namespace App\Pipelines\Support;

use App\Pipelines\Actions\BroadcastPipelineUpdatedAction;
use App\Pipelines\Enums\PipelineStepKey;
use App\Pipelines\Models\Pipeline;
use App\Pipelines\Models\PipelineStep;
use App\Pipelines\Transitions\PipelineStepTransitions;
use App\Pipelines\Transitions\PipelineTransitions;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The single writer for pipeline state.
 *
 * Every method locks the pipeline row (and the step it touches) inside one
 * transaction, applies a guarded transition, and tells open workspace pages
 * about it. Heartbeats are the exception: they only move last_activity_at, so
 * they deliberately skip the broadcast rather than trigger a live reload.
 */
final readonly class PipelineState
{
    public function __construct(
        private PipelineTransitions $transitions,
        private PipelineStepTransitions $stepTransitions,
        private BroadcastPipelineUpdatedAction $broadcastPipelineUpdated,
    ) {}

    /**
     * Apply several adjacent step transitions under one lock, one transaction
     * and one broadcast.
     *
     * Use this only where the transitions really are adjacent bookkeeping. Do
     * not wrap steps that have work between them — a step's start() exists so
     * observers see the stage begin, and holding the row lock across an archive
     * build or an upload would be far worse than the extra broadcasts.
     *
     * The callback's return value is ignored; typing it mixed lets callers use
     * the fluent style, whose arrow functions return the sequence itself.
     *
     * @param  callable(PipelineStepSequence): mixed  $steps
     */
    public function run(Pipeline $pipeline, callable $steps): Pipeline
    {
        return $this->locked($pipeline, function (Pipeline $locked) use ($steps): Pipeline {
            $steps(new PipelineStepSequence($locked, $this->stepTransitions, $this->lockStep(...)));

            // Notify open workspace pages once, for the sequence as a whole.
            $this->broadcastPipelineUpdated->handle($locked->fresh() ?? $locked);

            return $locked;
        });
    }

    public function start(Pipeline $pipeline, PipelineStepKey $stepKey): PipelineStep
    {
        return $this->onStep($pipeline, $stepKey, $this->stepTransitions->start(...));
    }

    public function complete(Pipeline $pipeline, PipelineStepKey $stepKey): PipelineStep
    {
        return $this->onStep($pipeline, $stepKey, $this->stepTransitions->complete(...));
    }

    public function skip(Pipeline $pipeline, PipelineStepKey $stepKey): PipelineStep
    {
        return $this->onStep($pipeline, $stepKey, $this->stepTransitions->skip(...));
    }

    /**
     * Fail the run at $stepKey, or the run as a whole when no step is known.
     *
     * The nullable key is not laziness: a job that dies before claiming a stage
     * leaves current_stage null, and those failures still have to land somewhere.
     */
    public function fail(
        Pipeline $pipeline,
        ?PipelineStepKey $stepKey = null,
        ?Throwable $exception = null,
        ?string $errorCode = null,
        ?string $errorMessage = null,
    ): PipelineStep|Pipeline {
        $message = $this->errorMessage($exception, $errorMessage);

        return $this->lockedAndBroadcast($pipeline, fn (Pipeline $locked): PipelineStep|Pipeline => $this->onStepOrPipeline(
            $locked,
            $stepKey,
            fn (PipelineStep $step): PipelineStep => $this->stepTransitions->fail($locked, $step, $message, $errorCode),
            fn (): Pipeline => $this->transitions->fail($locked, $message, $errorCode),
        ));
    }

    /**
     * Mark the run stuck at $stepKey, or the run as a whole when no step is known.
     */
    public function stuck(
        Pipeline $pipeline,
        ?PipelineStepKey $stepKey = null,
        ?Throwable $exception = null,
        ?string $errorCode = null,
        ?string $errorMessage = null,
    ): PipelineStep|Pipeline {
        $message = $this->errorMessage($exception, $errorMessage);

        return $this->lockedAndBroadcast($pipeline, fn (Pipeline $locked): PipelineStep|Pipeline => $this->onStepOrPipeline(
            $locked,
            $stepKey,
            fn (PipelineStep $step): PipelineStep => $this->stepTransitions->stuck($locked, $step, $message, $errorCode),
            fn (): Pipeline => $this->transitions->stuck($locked, $message, $errorCode),
        ));
    }

    /**
     * Close a queued or running run. User-initiated cancellation goes through
     * CancelPipelineAction instead, which also releases the subject and audits.
     */
    public function cancel(Pipeline $pipeline): Pipeline
    {
        return $this->lockedAndBroadcast($pipeline, fn (Pipeline $locked): Pipeline => $this->transitions->cancel($locked));
    }

    public function heartbeat(Pipeline $pipeline): Pipeline
    {
        return $this->locked($pipeline, fn (Pipeline $locked): Pipeline => $this->transitions->heartbeat($locked));
    }

    public function heartbeatStep(Pipeline $pipeline, PipelineStepKey $stepKey): PipelineStep
    {
        return $this->locked($pipeline, fn (Pipeline $locked): PipelineStep => $this->stepTransitions->heartbeat(
            $locked,
            $this->lockStep($locked, $stepKey),
        ));
    }

    /**
     * @param  callable(Pipeline, PipelineStep): PipelineStep  $transition
     */
    private function onStep(Pipeline $pipeline, PipelineStepKey $stepKey, callable $transition): PipelineStep
    {
        return $this->lockedAndBroadcast($pipeline, fn (Pipeline $locked): PipelineStep => $transition(
            $locked,
            $this->lockStep($locked, $stepKey),
        ));
    }

    /**
     * @param  callable(PipelineStep): PipelineStep  $onStep
     * @param  callable(): Pipeline  $onPipeline
     */
    private function onStepOrPipeline(
        Pipeline $pipeline,
        ?PipelineStepKey $stepKey,
        callable $onStep,
        callable $onPipeline,
    ): PipelineStep|Pipeline {
        if (! $stepKey instanceof PipelineStepKey) {
            return $onPipeline();
        }

        return $onStep($this->lockStep($pipeline, $stepKey));
    }

    /**
     * @template TResult of PipelineStep|Pipeline
     *
     * @param  callable(Pipeline): TResult  $operation
     * @return TResult
     */
    private function lockedAndBroadcast(Pipeline $pipeline, callable $operation): PipelineStep|Pipeline
    {
        return $this->locked($pipeline, function (Pipeline $locked) use ($operation): PipelineStep|Pipeline {
            $result = $operation($locked);

            // Notify open workspace pages.
            $this->broadcastPipelineUpdated->handle($locked->fresh() ?? $locked);

            return $result;
        });
    }

    /**
     * @template TResult of PipelineStep|Pipeline
     *
     * @param  callable(Pipeline): TResult  $operation
     * @return TResult
     */
    private function locked(Pipeline $pipeline, callable $operation): PipelineStep|Pipeline
    {
        return DB::transaction(function () use ($pipeline, $operation): PipelineStep|Pipeline {
            $pipelineQuery = Pipeline::query()->whereKey($pipeline->id);
            $pipelineQuery->getQuery()->lockForUpdate();

            return $operation($pipelineQuery->firstOrFail());
        });
    }

    private function lockStep(Pipeline $pipeline, PipelineStepKey $stepKey): PipelineStep
    {
        $stepQuery = PipelineStep::query()
            ->where('pipeline_id', $pipeline->id)
            ->where('key', $stepKey->value);
        $stepQuery->getQuery()->lockForUpdate();

        return $stepQuery->firstOrFail();
    }

    private function errorMessage(?Throwable $exception, ?string $message): ?string
    {
        $message ??= $exception?->getMessage();

        return $message === null || $message === '' ? null : mb_substr($message, 0, 500);
    }
}
