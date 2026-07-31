<?php

declare(strict_types=1);

namespace App\Dossiers\Data;

use App\DocumentProcessing\Data\PipelineData;
use App\Dossiers\Models\UploadedDocument;

/**
 * Uploaded file metadata for a staff document-request row.
 * List/review island payload — no OCR extraction body.
 */
final readonly class StaffUploadedDocumentData
{
    public function __construct(
        public int $id,
        public string $originalFilename,
        public int $sizeBytes,
        public string $uploadedAt,
        public string $processingStatus,
        public ?string $processingError,
        public ?PipelineData $pipeline,
    ) {}

    /**
     * Build the list/review slice from a document (loads pipelines when needed).
     */
    public static function fromModel(UploadedDocument $uploadedDocument): self
    {
        $uploadedDocument->loadMissing('pipelines.steps');

        $latestPipeline = $uploadedDocument->pipelines->sortByDesc('id')->first();

        return new self(
            id: $uploadedDocument->id,
            originalFilename: $uploadedDocument->original_filename,
            sizeBytes: $uploadedDocument->size_bytes,
            uploadedAt: $uploadedDocument->uploaded_at->toIso8601String(),
            processingStatus: $uploadedDocument->processing_status->value,
            processingError: $uploadedDocument->processing_error,
            pipeline: $latestPipeline === null
                ? null
                : PipelineData::fromModel($latestPipeline),
        );
    }

    /**
     * @return array{
     *     id: int,
     *     original_filename: string,
     *     size_bytes: int,
     *     uploaded_at: string,
     *     processing_status: string,
     *     processing_error: string|null,
     *     pipeline: array{id: int, type: string, status: string, current_stage: string|null, attempt_number: int, last_activity_at: string|null, steps: list<array{key: string, status: string, attempts: int, started_at: string|null, last_activity_at: string|null, completed_at: string|null, error_code: string|null, error_message: string|null}>}|null
     * }
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'original_filename' => $this->originalFilename,
            'size_bytes' => $this->sizeBytes,
            'uploaded_at' => $this->uploadedAt,
            'processing_status' => $this->processingStatus,
            'processing_error' => $this->processingError,
            'pipeline' => $this->pipeline?->toArray(),
        ];
    }
}
