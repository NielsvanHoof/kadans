<?php

declare(strict_types=1);

namespace App\DocumentProcessing\Jobs;

use App\Audit\Actions\LogAuditActivityAction;
use App\Audit\Enums\AuditEvent;
use App\DocumentProcessing\Enums\DocumentProcessingStatus;
use App\DocumentProcessing\Enums\OcrProcessingOutcome;
use App\DocumentProcessing\Services\Ocr\OcrOrchestrator;
use App\DocumentProcessing\Transitions\UploadedDocumentProcessingTransitions;
use App\Dossiers\Models\UploadedDocument;
use App\Pipelines\Enums\PipelineStepKey;
use App\Pipelines\Models\Pipeline;
use App\Pipelines\Support\PipelineState;
use App\Pipelines\Support\PipelineStepSequence;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\InteractsWithQueue;
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
        PipelineState $pipelineState,
        UploadedDocumentProcessingTransitions $processingTransitions,
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

        $claimed = $processingTransitions->claim($document, $expectedPath);

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
                $pipelineState->start($pipeline, PipelineStepKey::Submit);
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
            $updated = $processingTransitions->failForPath($claimed, $expectedPath, $error);

            if (! $updated) {
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

        resolve(PipelineState::class)->fail(
            $pipeline,
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
        PipelineState $pipelineState,
    ): void {
        $pipeline->forceFill([
            'provider' => (string) config('ocr.driver'),
            'provider_job_id' => $document->textract_job_id,
        ])->saveQuietly();

        if ($outcome === OcrProcessingOutcome::Deferred) {
            $pipelineState->run($pipeline, fn (PipelineStepSequence $steps): PipelineStepSequence => $steps
                ->complete(PipelineStepKey::Submit)
                ->start(PipelineStepKey::ProviderProcessing));

            return;
        }

        // The driver returned text inline, so nothing is deferred to a provider
        // callback — the rest is bookkeeping under one lock and one broadcast.
        $pipelineState->run($pipeline, fn (PipelineStepSequence $steps): PipelineStepSequence => $steps
            ->complete(PipelineStepKey::Submit)
            ->skip(PipelineStepKey::ProviderProcessing)
            ->start(PipelineStepKey::ProcessResults)
            ->complete(PipelineStepKey::ProcessResults));
    }
}
