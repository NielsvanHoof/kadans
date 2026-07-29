<?php

declare(strict_types=1);

namespace App\DocumentProcessing\Actions\Ocr;

use App\DocumentProcessing\Enums\PipelineStatus;
use App\DocumentProcessing\Enums\PipelineType;
use App\DocumentProcessing\Models\Pipeline;
use App\Dossiers\Models\UploadedDocument;

use function is_numeric;
use function is_string;

/**
 * Find the OCR pipeline for an uploaded document during Textract completion/failure.
 * Prefers a run already tagged with the provider job id, then the latest open OCR run.
 */
final class ResolveOcrPipelineForDocumentAction
{
    public function handle(UploadedDocument $document, ?string $providerJobId = null): ?Pipeline
    {
        $documentId = $document->getKey();

        if (! is_numeric($documentId)) {
            return null;
        }

        $query = Pipeline::query()
            ->where('pipelineable_type', $document->getMorphClass())
            ->where('pipelineable_id', (int) $documentId)
            ->where('type', PipelineType::OcrDocument->value);
        $query->getQuery()->orderByDesc('id');

        // Prefer the pipeline that already recorded this Textract job during submit.
        if (is_string($providerJobId) && $providerJobId !== '') {
            $matchedQuery = clone $query;
            $matchedQuery->getQuery()->where('provider_job_id', $providerJobId);
            $matched = $matchedQuery->first();

            if ($matched instanceof Pipeline) {
                return $matched;
            }
        }

        // Fall back to the latest OCR pipeline that can still accept step updates.
        $openQuery = clone $query;
        $openQuery->getQuery()->whereNotIn('status', [
            PipelineStatus::Completed->value,
            PipelineStatus::Cancelled->value,
        ]);

        return $openQuery->first();
    }
}
