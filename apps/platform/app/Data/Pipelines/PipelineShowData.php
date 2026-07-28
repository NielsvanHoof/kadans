<?php

declare(strict_types=1);

namespace App\Data\Pipelines;

use App\Models\Pipeline;
use App\Models\PipelineStep;

final readonly class PipelineShowData
{
    /**
     * @param  list<PipelineStepData>  $steps
     * @param  array<string, mixed>|null  $metadata
     */
    public function __construct(
        public int $id,
        public string $type,
        public string $status,
        public ?string $currentStage,
        public ?string $provider,
        public ?string $providerJobId,
        public int $attemptNumber,
        public ?string $errorCode,
        public ?string $errorMessage,
        public ?array $metadata,
        public ?string $startedAt,
        public ?string $lastActivityAt,
        public ?string $nextRetryAt,
        public ?string $completedAt,
        public ?string $failedAt,
        public array $steps,
        public ?PipelineSubjectData $subject,
    ) {}

    public static function fromModel(Pipeline $pipeline, ?PipelineSubjectData $subject): self
    {
        $steps = $pipeline->steps
            ->sortBy('position')
            ->map(static fn (PipelineStep $step): PipelineStepData => PipelineStepData::fromModel($step))
            ->values()
            ->all();

        /** @var list<PipelineStepData> $steps */
        return new self(
            id: $pipeline->id,
            type: $pipeline->type->value,
            status: $pipeline->status->value,
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

    /**
     * @return array{id: int, type: string, status: string, current_stage: string|null, provider: string|null, provider_job_id: string|null, attempt_number: int, error_code: string|null, error_message: string|null, metadata: array<string, mixed>|null, started_at: string|null, last_activity_at: string|null, next_retry_at: string|null, completed_at: string|null, failed_at: string|null, steps: list<array{key: string, status: string, attempts: int, started_at: string|null, last_activity_at: string|null, completed_at: string|null, error_code: string|null, error_message: string|null}>, subject: array{type: string, id: int, label: string|null, context: string|null, href: string|null}|null}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'status' => $this->status,
            'current_stage' => $this->currentStage,
            'provider' => $this->provider,
            'provider_job_id' => $this->providerJobId,
            'attempt_number' => $this->attemptNumber,
            'error_code' => $this->errorCode,
            'error_message' => $this->errorMessage,
            'metadata' => $this->metadata,
            'started_at' => $this->startedAt,
            'last_activity_at' => $this->lastActivityAt,
            'next_retry_at' => $this->nextRetryAt,
            'completed_at' => $this->completedAt,
            'failed_at' => $this->failedAt,
            'steps' => array_map(
                static fn (PipelineStepData $step): array => $step->toArray(),
                $this->steps,
            ),
            'subject' => $this->subject?->toArray(),
        ];
    }
}
