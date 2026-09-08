<?php

declare(strict_types=1);

namespace App\Dossiers\Data;

use App\DocumentProcessing\Enums\DocumentProcessingStatus;
use App\Dossiers\Models\UploadedDocument;
use App\Pipelines\Data\PipelineData;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Uploaded file metadata for a staff document-request row.
 * List/review island payload — no OCR extraction body.
 */
#[TypeScript]
final class StaffUploadedDocumentData extends Data
{
    public function __construct(
        public readonly int $id,
        public readonly string $originalFilename,
        public readonly int $sizeBytes,
        public readonly string $uploadedAt,
        public readonly DocumentProcessingStatus $processingStatus,
        public readonly ?string $processingError,
        public readonly ?PipelineData $pipeline,
        public readonly bool $canDelete,
    ) {}

    /**
     * Build the list/review slice from a document (loads the latest pipeline when needed).
     */
    public static function fromModel(UploadedDocument $uploadedDocument): self
    {
        $uploadedDocument->loadMissing('latestPipeline.steps');

        $latestPipeline = $uploadedDocument->latestPipeline;

        return new self(
            id: $uploadedDocument->id,
            originalFilename: $uploadedDocument->original_filename,
            sizeBytes: $uploadedDocument->size_bytes,
            uploadedAt: $uploadedDocument->uploaded_at->toIso8601String(),
            processingStatus: $uploadedDocument->processing_status,
            processingError: $uploadedDocument->processing_error,
            pipeline: $latestPipeline === null
                ? null
                : PipelineData::fromModel($latestPipeline),
            canDelete: request()->user()?->can('delete', $uploadedDocument) ?? false,
        );
    }
}
