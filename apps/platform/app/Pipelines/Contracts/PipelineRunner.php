<?php

declare(strict_types=1);

namespace App\Pipelines\Contracts;

use App\Pipelines\Enums\PipelineType;
use App\Pipelines\Models\Pipeline;
use Illuminate\Database\Eloquent\Model;

/**
 * Owned by the domain that runs a given pipeline type. Lets StartPipelineAction,
 * RetryPipelineAction and CancelPipelineAction queue a run and reset its subject
 * without Pipelines knowing about OCR/transcription/export specifics.
 *
 * One runner per type serves both the first attempt and every retry, so the two
 * paths cannot drift into queueing different jobs with different arguments.
 */
interface PipelineRunner
{
    public function type(): PipelineType;

    /**
     * @return class-string<Model>
     */
    public function subjectClass(): string;

    /**
     * Clear the in-flight state a stuck or failed attempt left behind, so the
     * re-queued job does not skip its work thinking another attempt is active.
     * Only ever called for a retry or a cancellation, never on a first attempt.
     */
    public function resetSubject(Model $subject): void;

    /**
     * Queue the work for this run.
     *
     * Implementations must dispatch `->afterCommit()`: callers create the
     * subject and its pipeline in one transaction, and a worker that picks the
     * job up before that commits would find neither row.
     */
    public function dispatch(Pipeline $pipeline, Model $subject): void;
}
