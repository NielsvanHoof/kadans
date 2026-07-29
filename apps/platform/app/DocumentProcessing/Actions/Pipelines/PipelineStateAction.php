<?php

declare(strict_types=1);

namespace App\DocumentProcessing\Actions\Pipelines;

use App\DocumentProcessing\Enums\PipelineStepOperation;
use App\DocumentProcessing\Models\Pipeline;
use App\DocumentProcessing\Models\PipelineStep;
use App\DocumentProcessing\Transitions\PipelineTransitions;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

final readonly class PipelineStateAction
{
    public function __construct(
        private PipelineTransitions $transitions,
    ) {}

    public function handle(
        Pipeline $pipeline,
        PipelineStepOperation $operation,
        ?string $stepKey = null,
        ?Throwable $exception = null,
        ?string $errorCode = null,
        ?string $errorMessage = null,
    ): PipelineStep|Pipeline {
        return DB::transaction(function () use (
            $pipeline,
            $operation,
            $stepKey,
            $exception,
            $errorCode,
            $errorMessage,
        ): PipelineStep|Pipeline {
            $pipelineQuery = Pipeline::query()->whereKey($pipeline->id);
            $pipelineQuery->getQuery()->lockForUpdate();
            $lockedPipeline = $pipelineQuery->firstOrFail();
            $message = $this->errorMessage($exception, $errorMessage);

            if ($operation === PipelineStepOperation::Heartbeat && $stepKey === null) {
                return $this->transitions->heartbeatPipeline($lockedPipeline);
            }

            $step = $stepKey === null
                ? null
                : $this->lockStep($lockedPipeline, $stepKey);

            if ($operation === PipelineStepOperation::Fail && ! $step instanceof PipelineStep) {
                return $this->transitions->failPipeline($lockedPipeline, $message, $errorCode);
            }

            if ($operation === PipelineStepOperation::Stuck && ! $step instanceof PipelineStep) {
                return $this->transitions->stuckPipeline($lockedPipeline, $message, $errorCode);
            }

            throw_unless($step instanceof PipelineStep, InvalidArgumentException::class, 'A pipeline step is required for this operation.');

            return match ($operation) {
                PipelineStepOperation::Start => $this->transitions->start($lockedPipeline, $step),
                PipelineStepOperation::Complete => $this->transitions->complete($lockedPipeline, $step),
                PipelineStepOperation::Fail => $this->transitions->fail($lockedPipeline, $step, $message, $errorCode),
                PipelineStepOperation::Skip => $this->transitions->skip($lockedPipeline, $step),
                PipelineStepOperation::Stuck => $this->transitions->stuck($lockedPipeline, $step, $message, $errorCode),
                PipelineStepOperation::Heartbeat => $this->transitions->heartbeat($lockedPipeline, $step),
            };
        });
    }

    private function lockStep(Pipeline $pipeline, string $stepKey): PipelineStep
    {
        $stepQuery = PipelineStep::query()
            ->where('pipeline_id', $pipeline->id)
            ->where('key', $stepKey);
        $stepQuery->getQuery()->lockForUpdate();

        return $stepQuery->firstOrFail();
    }

    private function errorMessage(?Throwable $exception, ?string $message): ?string
    {
        $message ??= $exception?->getMessage();

        return $message === null || $message === '' ? null : mb_substr($message, 0, 500);
    }
}
