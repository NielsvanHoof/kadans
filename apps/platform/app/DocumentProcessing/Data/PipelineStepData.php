<?php

declare(strict_types=1);

namespace App\DocumentProcessing\Data;

use App\DocumentProcessing\Models\PipelineStep;

final readonly class PipelineStepData
{
    public function __construct(
        public string $key,
        public string $status,
        public int $attempts,
        public ?string $startedAt,
        public ?string $lastActivityAt,
        public ?string $completedAt,
        public ?string $errorCode,
        public ?string $errorMessage,
    ) {}

    public static function fromModel(PipelineStep $step): self
    {
        return new self(
            key: $step->key,
            status: $step->status->value,
            attempts: $step->attempts,
            startedAt: $step->started_at?->toIso8601String(),
            lastActivityAt: $step->last_activity_at?->toIso8601String(),
            completedAt: $step->completed_at?->toIso8601String(),
            errorCode: $step->error_code,
            errorMessage: $step->error_message,
        );
    }

    /**
     * @return array{key: string, status: string, attempts: int, started_at: string|null, last_activity_at: string|null, completed_at: string|null, error_code: string|null, error_message: string|null}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'status' => $this->status,
            'attempts' => $this->attempts,
            'started_at' => $this->startedAt,
            'last_activity_at' => $this->lastActivityAt,
            'completed_at' => $this->completedAt,
            'error_code' => $this->errorCode,
            'error_message' => $this->errorMessage,
        ];
    }
}
