<?php

declare(strict_types=1);

namespace App\DocumentProcessing\Transitions;

use App\DocumentProcessing\Enums\DocumentProcessingStatus;
use App\Dossiers\Models\UploadedDocument;
use Illuminate\Support\Facades\DB;

/**
 * Guarded processing_status transitions for uploaded documents (OCR).
 *
 * Flow: pending|failed → processing (claim) → completed|failed.
 * Claim refuses a stale path and an in-flight Textract job id.
 * Completion and provider failure require processing + matching path/job id.
 */
final class UploadedDocumentProcessingTransitions
{
    /**
     * Atomically move pending/failed/processing → processing for this path revision.
     * Returns null when the document cannot be claimed.
     */
    public function claim(UploadedDocument $document, string $expectedPath): ?UploadedDocument
    {
        return DB::transaction(function () use ($document, $expectedPath): ?UploadedDocument {
            $locked = $document->refreshForUpdate();

            // Stale job for a replaced upload revision — skip without failing the queue.
            if ($locked->path !== $expectedPath) {
                return null;
            }

            // Another worker finished, or an external OCR job is already in flight.
            if (
                $locked->processing_status === DocumentProcessingStatus::Completed
                || (
                    $locked->processing_status === DocumentProcessingStatus::Processing
                    && $locked->textract_job_id !== null
                )
            ) {
                return null;
            }

            $locked->forceFill([
                'processing_status' => DocumentProcessingStatus::Processing,
                'processing_error' => null,
                'extracted_text' => null,
                'textract_job_id' => null,
                'processing_started_at' => $locked->processing_started_at ?? now(),
                'processing_finished_at' => null,
            ])->save();

            return $locked;
        });
    }

    /**
     * Reset to pending after a retryable job failure so the next attempt can reclaim.
     *
     * Without this a failure that had already reached Textract left the row on
     * Processing WITH a job id, which claim() reads as "an external job is
     * already in flight". Every later attempt then declined to claim and
     * returned successfully, so the exhausted-failure branch never ran: no
     * processing_error, no DocumentProcessingFailed entry, and a review card
     * that span forever. Nothing recovered it — there is no scheduled OCR
     * sweep, and StuckPipelinesCheck watches Pipeline rows, not
     * processing_status.
     *
     * The path guard is what keeps this safe: a job holding a replaced upload
     * revision must not reset the current one.
     *
     * The cost is a duplicated provider job — the original Textract job is
     * orphaned and its completion will not match the new job id. That is the
     * same trade the transcription path already makes, and a duplicate charge
     * beats a document that never finishes.
     */
    public function resetForRetry(UploadedDocument $document, string $expectedPath): void
    {
        UploadedDocument::query()
            ->whereKey($document->id)
            ->where('path', $expectedPath)
            ->update([
                'processing_status' => DocumentProcessingStatus::Pending->value,
                'textract_job_id' => null,
                'processing_started_at' => null,
            ]);
    }

    /**
     * Complete a processing document for a known Textract job + path revision.
     *
     * @return bool True when exactly one row was updated
     */
    public function complete(
        UploadedDocument $document,
        string $jobId,
        string $extractedText,
    ): bool {
        $updated = UploadedDocument::query()
            ->whereKey($document->id)
            ->where('path', $document->path)
            ->where('textract_job_id', $jobId)
            ->where('processing_status', DocumentProcessingStatus::Processing->value)
            ->update([
                'processing_status' => DocumentProcessingStatus::Completed->value,
                'extracted_text' => $extractedText,
                'processing_error' => null,
                'processing_finished_at' => now(),
            ]);

        return $updated === 1;
    }

    /**
     * Mark a processing document failed for a known Textract job + path revision.
     *
     * @return bool True when exactly one row was updated
     */
    public function failWhileProcessing(
        UploadedDocument $document,
        string $jobId,
        string $error,
    ): bool {
        $updated = UploadedDocument::query()
            ->whereKey($document->id)
            ->where('path', $document->path)
            ->where('textract_job_id', $jobId)
            ->where('processing_status', DocumentProcessingStatus::Processing->value)
            ->update([
                'processing_status' => DocumentProcessingStatus::Failed->value,
                'processing_error' => mb_substr($error, 0, 500),
                'processing_finished_at' => now(),
            ]);

        return $updated === 1;
    }

    /**
     * Permanent failure after job exhaustion — keyed by path only (no provider job id).
     *
     * @return bool True when exactly one row was updated
     */
    public function failForPath(
        UploadedDocument $document,
        string $expectedPath,
        string $error,
    ): bool {
        $updated = UploadedDocument::query()
            ->whereKey($document->id)
            ->where('path', $expectedPath)
            ->update([
                'processing_status' => DocumentProcessingStatus::Failed->value,
                'processing_error' => mb_substr($error, 0, 500),
                'processing_finished_at' => now(),
            ]);

        return $updated === 1;
    }
}
