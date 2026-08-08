<?php

declare(strict_types=1);

namespace App\Pipelines\Data;

use App\Pipelines\Enums\PipelineStatus;
use App\Pipelines\Enums\PipelineStepKey;
use App\Pipelines\Enums\PipelineType;
use App\Pipelines\Models\Pipeline;
use App\Pipelines\Models\PipelineStep;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class PipelineShowData extends Data
{
    /**
     * @param  list<PipelineStepData>  $steps
     * @param  array<string, mixed>|null  $metadata
     */
    public function __construct(
        public readonly int $id,
        public readonly PipelineType $type,
        public readonly PipelineStatus $status,
        public readonly ?PipelineStepKey $currentStage,
        public readonly ?string $provider,
        public readonly ?string $providerJobId,
        public readonly int $attemptNumber,
        public readonly ?string $errorCode,
        public readonly ?string $errorMessage,
        public readonly ?array $metadata,
        public readonly ?string $startedAt,
        public readonly ?string $lastActivityAt,
        public readonly ?string $nextRetryAt,
        public readonly ?string $completedAt,
        public readonly ?string $failedAt,
        public readonly array $steps,
        public readonly PipelineSubjectData $subject,
    ) {}

    public static function fromModel(Pipeline $pipeline, PipelineSubjectData $subject): self
    {
        $steps = $pipeline->steps
            ->sortBy('position')
            ->map(static fn (PipelineStep $step): PipelineStepData => PipelineStepData::fromModel($step))
            ->values()
            ->all();

        /** @var list<PipelineStepData> $steps */
        return new self(
            id: $pipeline->id,
            type: $pipeline->type,
            status: $pipeline->status,
            currentStage: $pipeline->current_stage,
            provider: $pipeline->provider,
            providerJobId: $pipeline->provider_job_id,
            attemptNumber: $pipeline->attempt_number,
            errorCode: $pipeline->error_code,
            errorMessage: $pipeline->error_message,
            metadata: $pipeline->metadata,
            startedAt: $pipeline->started_at?->toIso8601String(),
            lastActivityAt: $pipeline->last_activity_at?->toIso8601String(),
            nextRetryAt: $pipeline->next_retry_at?->toIso8601String(),
            completedAt: $pipeline->completed_at?->toIso8601String(),
            failedAt: $pipeline->failed_at?->toIso8601String(),
            steps: $steps,
            subject: $subject,
        );
    }
}
