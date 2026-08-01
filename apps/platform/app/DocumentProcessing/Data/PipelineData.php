<?php

declare(strict_types=1);

namespace App\DocumentProcessing\Data;

use App\DocumentProcessing\Enums\PipelineStatus;
use App\DocumentProcessing\Enums\PipelineType;
use App\DocumentProcessing\Models\Pipeline;
use App\DocumentProcessing\Models\PipelineStep;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class PipelineData extends Data
{
    /**
     * @param  list<PipelineStepData>  $steps
     */
    public function __construct(
        public readonly int $id,
        public readonly PipelineType $type,
        public readonly PipelineStatus $status,
        public readonly ?string $currentStage,
        public readonly int $attemptNumber,
        public readonly ?string $lastActivityAt,
        public readonly array $steps,
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
            type: $pipeline->type,
            status: $pipeline->status,
            currentStage: $pipeline->current_stage,
            attemptNumber: $pipeline->attempt_number,
            lastActivityAt: $pipeline->last_activity_at?->toIso8601String(),
            steps: $stepData,
        );
    }
}
