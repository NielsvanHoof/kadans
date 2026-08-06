<?php

declare(strict_types=1);

namespace App\Pipelines\Contracts;

use App\Pipelines\Enums\PipelineType;
use App\Pipelines\Models\Pipeline;
use Illuminate\Database\Eloquent\Model;

/**
 * Owned by the domain that runs a given pipeline type. Lets RetryPipelineAction
 * reset the subject and re-queue the run without Pipelines knowing about
 * OCR/transcription/export specifics.
 */
interface PipelineRetryRunner
{
    public function type(): PipelineType;

    /**
     * @return class-string<Model>
     */
    public function subjectClass(): string;

    /**
     * Clear the in-flight state a stuck or failed attempt left behind, so the
     * re-queued job does not skip its work thinking another attempt is active.
     */
    public function resetSubject(Model $subject): void;

    public function dispatch(Pipeline $pipeline, Model $subject): void;
}
