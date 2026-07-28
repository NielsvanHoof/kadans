<?php

declare(strict_types=1);

namespace App\DocumentProcessing\Data;

use App\DocumentProcessing\Models\Pipeline;
use App\DocumentProcessing\Models\PipelineStep;

final readonly class PipelineData
{
    /**
     * @param  list<PipelineStepData>  $steps
     */
    public function __construct(
        public int $id,
        public string $type,
        public string $status,
        public ?string $currentStage,
        public int $attemptNumber,
        public ?string $lastActivityAt,
        public array $steps,
    ) {}

    public static function fromModel(Pipeline $pipeline): self
    {
        if (! $pipeline->relationLoaded('steps')) {
            $pipeline->load('steps');
        }

        $steps = $pipeline->steps;
        /** @var list<PipelineStepData> $stepData */
        $stepData = $steps
            ->sortBy('position')
            ->map(static fn (PipelineStep $step): PipelineStepData => PipelineStepData::fromModel($step))
            ->values()
            ->all();

        return new self(
            id: $pipeline->id,
            type: $pipeline->type->value,
            status: $pipeline->status->value,
            currentStage: $pipeline->current_stage,
            attemptNumber: $pipeline->attempt_number,
            lastActivityAt: $pipeline->last_activity_at?->toIso8601String(),
            steps: $stepData,
        );
    }

    /**
     * @return array{id: int, type: string, status: string, current_stage: string|null, attempt_number: int, last_activity_at: string|null, steps: list<array{key: string, status: string, attempts: int, started_at: string|null, last_activity_at: string|null, completed_at: string|null, error_code: string|null, error_message: string|null}>}
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
            'steps' => array_map(
                static fn (PipelineStepData $step): array => $step->toArray(),
                $this->steps,
            ),
        ];
    }
}
