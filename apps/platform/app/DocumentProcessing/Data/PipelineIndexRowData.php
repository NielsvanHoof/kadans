<?php

declare(strict_types=1);

namespace App\DocumentProcessing\Data;

use App\DocumentProcessing\Enums\PipelineStatus;
use App\DocumentProcessing\Enums\PipelineType;
use App\DocumentProcessing\Models\Pipeline;
use App\Shared\Database\MorphType;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class PipelineIndexRowData extends Data
{
    public function __construct(
        public readonly int $id,
        public readonly PipelineType $type,
        public readonly PipelineStatus $status,
        public readonly ?string $currentStage,
        public readonly int $attemptNumber,
        public readonly ?string $lastActivityAt,
        public readonly string $createdAt,
        public readonly string $subjectType,
        public readonly int $subjectId,
    ) {}

    public static function fromModel(Pipeline $pipeline): self
    {
        return new self(
            id: $pipeline->id,
            type: $pipeline->type,
            status: $pipeline->status,
            currentStage: $pipeline->current_stage,
            attemptNumber: $pipeline->attempt_number,
            lastActivityAt: $pipeline->last_activity_at?->toIso8601String(),
            createdAt: $pipeline->created_at?->toIso8601String() ?? '',
            subjectType: MorphType::classBasename($pipeline->pipelineable_type),
            subjectId: $pipeline->pipelineable_id,
        );
    }
}
