<?php

declare(strict_types=1);

namespace App\Data\Pipelines;

use App\Models\Pipeline;

final readonly class PipelineIndexRowData
{
    public function __construct(
        public int $id,
        public string $type,
        public string $status,
        public ?string $currentStage,
        public int $attemptNumber,
        public ?string $lastActivityAt,
        public string $createdAt,
        public string $subjectType,
        public ?int $subjectId,
    ) {}

    public static function fromModel(Pipeline $pipeline): self
    {
        return new self(
            id: $pipeline->id,
            type: $pipeline->type->value,
            status: $pipeline->status->value,
            currentStage: $pipeline->current_stage,
            attemptNumber: $pipeline->attempt_number,
            lastActivityAt: $pipeline->last_activity_at?->toIso8601String(),
            createdAt: $pipeline->created_at->toIso8601String(),
            subjectType: class_basename($pipeline->pipelineable_type ?? 'Unknown'),
            subjectId: $pipeline->pipelineable_id,
        );
    }

    /**
     * @return array{id: int, type: string, status: string, current_stage: string|null, attempt_number: int, last_activity_at: string|null, created_at: string, subject_type: string, subject_id: int|null}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'status' => $this->status,
            'current_stage' => $this->currentStage,
            'attempt_number' => $this->attemptNumber,
            'last_activity_at' => $this->lastActivityAt,
            'created_at' => $this->createdAt,
            'subject_type' => $this->subjectType,
            'subject_id' => $this->subjectId,
        ];
    }
}
