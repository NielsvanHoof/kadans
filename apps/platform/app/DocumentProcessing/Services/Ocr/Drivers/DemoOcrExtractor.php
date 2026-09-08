<?php

declare(strict_types=1);

namespace App\DocumentProcessing\Services\Ocr\Drivers;

use App\DocumentProcessing\Contracts\Ocr\DocumentOcr;
use App\DocumentProcessing\Enums\DocumentProcessingStatus;
use App\DocumentProcessing\Services\Ocr\FlattenExtractedTextForSearch;
use App\Dossiers\Models\UploadedDocument;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;

/**
 * Offline OCR driver for live demos.
 *
 * Behaves like MockOcrExtractor — no external calls, completes inside the upload
 * job — but writes a realistic Dutch extraction instead of file metadata, and
 * pauses briefly so the pipeline's progress broadcast is actually visible on
 * screen. Both differences exist for the audience, not for correctness, which is
 * why they are not folded into the mock that CI asserts against.
 */
final readonly class DemoOcrExtractor implements DocumentOcr
{
    public function __construct(private Repository $config) {}

    public function start(UploadedDocument $document): void
    {
        $this->pauseForEffect();

        $extractedText = $this->extract($document);

        // Same compare-and-swap as the mock: a re-upload while this was running
        // means a newer revision owns the row, and this result is stale.
        $updated = UploadedDocument::query()
            ->whereKey($document->id)
            ->where('path', $document->path)
            ->where('processing_status', DocumentProcessingStatus::Processing->value)
            ->update([
                'processing_status' => DocumentProcessingStatus::Completed->value,
                'extracted_text' => $extractedText,
                'searchable_text' => new FlattenExtractedTextForSearch()->searchableText(
                    $document->original_filename,
                    $extractedText,
                ),
                'embedding' => null,
                'processing_error' => null,
                'processing_finished_at' => now(),
                'textract_job_id' => null,
            ]);

        if ($updated !== 1) {
            Log::warning('OCR: discarded demo extraction for a stale upload revision.', [
                'uploaded_document_id' => $document->id,
                'tenant_id' => $document->tenant_id,
                'expected_path' => $document->path,
            ]);

            return;
        }

        $document->refresh();
        $document->searchable();
    }

    public function extract(UploadedDocument $document): string
    {
        return DemoExtractionSamples::forTitle($document->original_filename);
    }

    /**
     * An instant completion skips the progress animation entirely, which is the
     * part of the pipeline worth showing. Set OCR_DEMO_DELAY_SECONDS=0 to drop it.
     */
    private function pauseForEffect(): void
    {
        $seconds = (int) $this->config->get('ocr.demo.delay_seconds', 3);

        if ($seconds > 0) {
            Sleep::sleep(min($seconds, 15));
        }
    }
}
