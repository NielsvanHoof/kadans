<?php

declare(strict_types=1);

namespace App\DocumentProcessing\Jobs;

use App\Audit\Actions\LogAuditActivityAction;
use App\Audit\Enums\AuditEvent;
use App\DocumentProcessing\Enums\DocumentProcessingStatus;
use App\DocumentProcessing\Enums\OcrProcessingOutcome;
use App\DocumentProcessing\Services\Ocr\OcrOrchestrator;
use App\Dossiers\Models\UploadedDocument;
use App\Pipelines\Actions\PipelineStateAction;
use App\Pipelines\Enums\PipelineStepOperation;
use App\Pipelines\Models\Pipeline;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

use function hash;

/**
 * Async OCR pipeline for an uploaded document.
 * Mock driver completes in-process; Textract stays processing until SNS/SQS completion.
 */
#[Backoff(self::BACKOFF_SECONDS)]
#[Tries(self::MAX_TRIES)]
#[Timeout(self::TIMEOUT_SECONDS)]
final class ProcessUploadedDocumentJob implements ShouldBeUnique, ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    public const int MAX_TRIES = 3;

    public const int TIMEOUT_SECONDS = 120;

    /** @var list<int> */
    public const array BACKOFF_SECONDS = [10, 30, 60];

    public function __construct(
        public int $uploadedDocumentId,
        public ?string $expectedPath = null,
        public ?int $pipelineId = null
    ) {}

    public function uniqueId(): string
    {
        return "{$this->uploadedDocumentId}:{$this->pipelineId}:".hash('sha256', $this->expectedPath ?? 'legacy');
    }

    /**
     * Auto-release the unique lock after worst-case attempts + backoff so a
     * SIGKILL / OOM cannot wedge the subject forever
     */
    public function uniqueFor(): int
    {
        return (self::TIMEOUT_SECONDS * self::MAX_TRIES) + array_sum(self::BACKOFF_SECONDS);
    }

    /**
     * Telescope / Horizon searchable tags (jobs carry IDs, not Eloquent models).
     *
     * @return list<string>
     */
    public function tags(): array
    {
        $tags = [
            'ocr',
            'uploaded_document:'.$this->uploadedDocumentId,
        ];

        if ($this->pipelineId !== null) {
            $tags[] = 'pipeline:'.$this->pipelineId;
        }

        return $tags;
    }

    public function handle(
        OcrOrchestrator $ocrOrchestrator,
        LogAuditActivityAction $logAuditActivity,
        PipelineStateAction $pipelineState,
    ): void {
        $document = UploadedDocument::query()->findOrFail($this->uploadedDocumentId);

        $expectedPath = $this->expectedPath ?? $document->path;

        if ($document->processing_status === DocumentProcessingStatus::Completed) {
            Log::info('OCR: skipped ProcessUploadedDocumentJob — already completed.', [
                'uploaded_document_id' => $document->id,
                'tenant_id' => $document->tenant_id,
            ]);

            return;
        }

        $claimed = $this->claimForProcessing($document, $expectedPath);

        if (! $claimed instanceof UploadedDocument) {
            Log::info('OCR: skipped ProcessUploadedDocumentJob — could not claim document.', [
                'uploaded_document_id' => $this->uploadedDocumentId,
                'expected_path' => $expectedPath,
            ]);

            return;
        }

        Log::info('OCR: ProcessUploadedDocumentJob claimed document.', [
            'uploaded_document_id' => $claimed->id,
            'tenant_id' => $claimed->tenant_id,
            'attempt' => $this->attempts(),
            'original_filename' => $claimed->original_filename,
        ]);

        try {
            $pipeline = $this->resolvePipeline();

            if ($pipeline instanceof Pipeline) {
                $pipelineState->handle($pipeline, PipelineStepOperation::Start, 'submit');
            }

            $outcome = $ocrOrchestrator->startProcessing($claimed);

            if ($pipeline instanceof Pipeline) {
                $this->recordOutcome($pipeline, $outcome, $claimed, $pipelineState);
            }
        } catch (Throwable $exception) {
            // Let the queue retry until tries are exhausted.
            if ($this->attempts() < self::MAX_TRIES) {
                Log::warning('OCR: ProcessUploadedDocumentJob failed — will retry.', [
                    'uploaded_document_id' => $claimed->id,
                    'tenant_id' => $claimed->tenant_id,
                    'attempt' => $this->attempts(),
                    'tries' => self::MAX_TRIES,
                    'error' => $exception->getMessage(),
                ]);

                throw $exception;
            }

            $error = mb_substr($exception->getMessage(), 0, 500);
            $updated = UploadedDocument::query()
                ->whereKey($claimed->id)
                ->where('path', $expectedPath)
                ->update([
                    'processing_status' => DocumentProcessingStatus::Failed->value,
                    'processing_error' => $error,
                    'processing_finished_at' => now(),
                ]);

            if ($updated !== 1) {
                Log::warning('OCR: discarded permanent failure for a stale upload revision.', [
                    'uploaded_document_id' => $claimed->id,
                    'tenant_id' => $claimed->tenant_id,
                    'expected_path' => $expectedPath,
                    'error' => $error,
                ]);

                throw $exception;
            }

            $claimed->refresh();

            Log::error('OCR: ProcessUploadedDocumentJob failed permanently.', [
                'uploaded_document_id' => $claimed->id,
                'tenant_id' => $claimed->tenant_id,
                'attempt' => $this->attempts(),
                'error' => $error,
            ]);

            $logAuditActivity->handle(
                AuditEvent::DocumentProcessingFailed,
                $claimed,
                [
                    'original_filename' => $claimed->original_filename,
                    'error' => $claimed->processing_error,
                ],
                includeRequestContext: false,
            );

            throw $exception;
        }
    }

    public function failed(Throwable $exception): void
    {
        $pipeline = $this->resolvePipeline();

        // failed() is invoked as $job->failed($e) — resolve deps from the container.
        if (! $pipeline instanceof Pipeline || $pipeline->status->isTerminal()) {
            return;
        }

        resolve(PipelineStateAction::class)->handle(
            $pipeline,
            PipelineStepOperation::Fail,
            $pipeline->current_stage,
            $exception,
            errorCode: 'ocr_job_failed',
        );
    }

    private function resolvePipeline(): ?Pipeline
    {
        if ($this->pipelineId === null) {
            return null;
        }

        return Pipeline::query()->find($this->pipelineId);
    }

    private function recordOutcome(
        Pipeline $pipeline,
        OcrProcessingOutcome $outcome,
        UploadedDocument $document,
        PipelineStateAction $pipelineState,
    ): void {
        $pipeline->forceFill([
            'provider' => (string) config('ocr.driver'),
            'provider_job_id' => $document->textract_job_id,
        ])->saveQuietly();

        $pipelineState->handle($pipeline, PipelineStepOperation::Complete, 'submit');

        if ($outcome === OcrProcessingOutcome::Deferred) {
            $pipelineState->handle($pipeline, PipelineStepOperation::Start, 'provider_processing');

            return;
        }

        $pipelineState->handle($pipeline, PipelineStepOperation::Skip, 'provider_processing');
        $pipelineState->handle($pipeline, PipelineStepOperation::Start, 'process_results');
        $pipelineState->handle($pipeline, PipelineStepOperation::Complete, 'process_results');
    }

    /**
     * Atomically move pending/failed/processing → processing.
     * Returns null when the document cannot be claimed (stale path, already
     * completed, or an external OCR job already in flight).
     */
    private function claimForProcessing(
        UploadedDocument $document,
        string $expectedPath,
    ): ?UploadedDocument {
        return DB::transaction(function () use ($document, $expectedPath): ?UploadedDocument {
            $query = UploadedDocument::query()->whereKey($document->getKey());
            $query->getQuery()->lockForUpdate();

            $locked = $query->firstOrFail();

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
}
