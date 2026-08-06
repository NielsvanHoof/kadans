<?php

declare(strict_types=1);

namespace App\Pipelines\Data;

use App\Pipelines\Enums\PipelineStepStatus;
use App\Pipelines\Models\PipelineStep;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class PipelineStepData extends Data
{
    public function __construct(
        public readonly string $key,
        public readonly PipelineStepStatus $status,
        public readonly int $attempts,
        public readonly ?string $startedAt,
        public readonly ?string $lastActivityAt,
        public readonly ?string $completedAt,
        public readonly ?string $errorCode,
        public readonly ?string $errorMessage,
    ) {}

    public static function fromModel(PipelineStep $step): self
    {
        return new self(
            key: $step->key,
            status: $step->status,
            attempts: $step->attempts,
            startedAt: $step->started_at?->toIso8601String(),
            lastActivityAt: $step->last_activity_at?->toIso8601String(),
            completedAt: $step->completed_at?->toIso8601String(),
            errorCode: $step->error_code,
            errorMessage: $step->error_message,
        );
    }
}
