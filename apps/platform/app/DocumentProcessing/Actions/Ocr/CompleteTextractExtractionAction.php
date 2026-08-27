<?php

declare(strict_types=1);

namespace App\DocumentProcessing\Actions\Ocr;

use App\Audit\Actions\LogAuditActivityAction;
use App\Audit\Enums\AuditEvent;
use App\DocumentProcessing\Enums\DocumentProcessingStatus;
use App\DocumentProcessing\Services\Ocr\TextractExtractionPipeline;
use App\DocumentProcessing\Transitions\UploadedDocumentProcessingTransitions;
use App\Dossiers\Models\UploadedDocument;
use App\Pipelines\Actions\PrepareProviderPipelineForProviderResultAction;
use App\Pipelines\Actions\ResolveProviderPipelineForSubjectAction;
use App\Pipelines\Enums\PipelineStepKey;
use App\Pipelines\Enums\PipelineType;
use App\Pipelines\Models\Pipeline;
use App\Pipelines\Support\PipelineState;
use App\Pipelines\Support\PipelineStepSequence;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;

use function in_array;
use function is_string;

/**
 * Applies a Textract AnalyzeDocument SNS completion to an UploadedDocument.
 * Maps FORMS/TABLES via the extraction pipeline, then persists status + audit.
 *
 * Tenant is already current: the consume command switches before dispatch so
 * Spatie restores it onto this job. A scoped lookup is therefore correct.
 */
final readonly class CompleteTextractExtractionAction
{
    public function __construct(
        private TextractExtractionPipeline $extractionPipeline,
        private MarkTextractExtractionFailedAction $markTextractExtractionFailed,
        private LogAuditActivityAction $logAuditActivity,
        private PipelineState $pipelineState,
        private ResolveProviderPipelineForSubjectAction $resolveProviderPipeline,
        private PrepareProviderPipelineForProviderResultAction $prepareProviderPipeline,
        private UploadedDocumentProcessingTransitions $processingTransitions,
    ) {}

    /**
     * @param  array{JobId?: mixed, Status?: mixed, StatusMessage?: mixed, API?: mixed}  $notification
     */
    public function handle(array $notification): bool
    {
        $jobId = $notification['JobId'] ?? null;
        $status = $notification['Status'] ?? null;

        if (! is_string($jobId) || $jobId === '') {
            Log::warning('OCR: Textract completion ignored — missing JobId.');

            return false;
        }

        if (! is_string($status) || $status === '') {
            Log::warning('OCR: Textract completion ignored — missing Status.', [
                'textract_job_id' => $jobId,
            ]);

            return false;
        }

        $api = $notification['API'] ?? null;
        if (is_string($api) && $api !== '' && $api !== 'StartDocumentAnalysis') {
            Log::info('OCR: Textract completion ignored — unexpected API.', [
                'textract_job_id' => $jobId,
                'status' => $status,
                'api' => $api,
            ]);

            return false;
        }

        $document = UploadedDocument::query()
            ->where('textract_job_id', $jobId)
            ->first();

        if (! $document instanceof UploadedDocument) {
            // Common when SQS still has old messages or a job was re-started with a new id.
            Log::warning('OCR: Textract completion has no matching uploaded document.', [
                'textract_job_id' => $jobId,
                'status' => $status,
                'api' => is_string($api) ? $api : null,
            ]);

            return false;
        }

        return $this->apply($document, $notification, $jobId, $status);
    }

    /**
     * @param  array{JobId?: mixed, Status?: mixed, StatusMessage?: mixed, API?: mixed}  $notification
     */
    private function apply(
        UploadedDocument $document,
        array $notification,
        string $jobId,
        string $status,
    ): bool {
        $pipeline = $this->resolveProviderPipeline->handle(
            $document,
            PipelineType::OcrDocument,
            $jobId,
        );

        if (in_array($document->processing_status, [
            DocumentProcessingStatus::Completed,
            DocumentProcessingStatus::Failed,
        ], true)) {
            // Document may already be done while the pipeline stayed pending
            if (
                $document->processing_status === DocumentProcessingStatus::Completed
                && $pipeline instanceof Pipeline
                && ! $pipeline->status->isTerminal()
            ) {
                $pipeline = $this->prepareProviderPipeline->handle(
                    $pipeline,
                    Config::string('ocr.driver'),
                    $jobId,
                    PipelineStepKey::ProviderProcessing,
                );
                $this->pipelineState->run($pipeline, fn (PipelineStepSequence $steps): PipelineStepSequence => $steps
                    ->complete(PipelineStepKey::ProviderProcessing)
                    ->start(PipelineStepKey::ProcessResults)
                    ->complete(PipelineStepKey::ProcessResults));
            }

            Log::info('OCR: Textract completion already applied — skipping.', [
                'uploaded_document_id' => $document->id,
                'tenant_id' => $document->tenant_id,
                'textract_job_id' => $jobId,
            ]);

            return true;
        }

        Log::info('OCR: applying Textract completion notification.', [
            'uploaded_document_id' => $document->id,
            'tenant_id' => $document->tenant_id,
            'textract_job_id' => $jobId,
            'status' => $status,
        ]);

        if ($status === 'SUCCEEDED') {
            if ($pipeline instanceof Pipeline) {
                // Catch up submit/provider_processing when the submit job never recorded them.
                $pipeline = $this->prepareProviderPipeline->handle(
                    $pipeline,
                    Config::string('ocr.driver'),
                    $jobId,
                    PipelineStepKey::ProviderProcessing,
                );
                $this->pipelineState->run($pipeline, fn (PipelineStepSequence $steps): PipelineStepSequence => $steps
                    ->complete(PipelineStepKey::ProviderProcessing)
                    ->start(PipelineStepKey::ProcessResults));
            }

            $startedAt = hrtime(true);
            $extractedJson = $this->extractionPipeline->run($jobId, $document);
            $durationMs = (int) round((hrtime(true) - $startedAt) / 1_000_000);

            $updated = $this->processingTransitions->complete($document, $jobId, $extractedJson);

            if (! $updated) {
                Log::warning('OCR: discarded stale Textract completion.', [
                    'uploaded_document_id' => $document->id,
                    'tenant_id' => $document->tenant_id,
                    'textract_job_id' => $jobId,
                    'expected_path' => $document->path,
                ]);

                return true;
            }

            $document->refresh();

            if ($pipeline instanceof Pipeline) {
                $this->pipelineState->complete($pipeline, PipelineStepKey::ProcessResults);
            }

            $this->logAuditActivity->handle(
                AuditEvent::DocumentProcessingCompleted,
                $document,
                [
                    'original_filename' => $document->original_filename,
                    'extracted_length' => mb_strlen($extractedJson),
                    'textract_job_id' => $jobId,
                ],
                includeRequestContext: false,
            );

            Log::info('OCR: Textract extraction completed successfully.', [
                'uploaded_document_id' => $document->id,
                'tenant_id' => $document->tenant_id,
                'textract_job_id' => $jobId,
                'extracted_length' => mb_strlen($extractedJson),
                'duration_ms' => $durationMs,
            ]);

            return true;
        }

        if (in_array($status, ['FAILED', 'ERROR'], true)) {
            $message = $notification['StatusMessage'] ?? "Textract job reported {$status}.";
            $error = is_string($message) && $message !== ''
                ? mb_substr($message, 0, 500)
                : "Textract job reported {$status}.";

            $this->markTextractExtractionFailed->handle($document, $jobId, $error, $status);

            if ($pipeline instanceof Pipeline) {
                $this->pipelineState->fail(
                    $pipeline,
                    PipelineStepKey::ProviderProcessing,
                    errorCode: 'textract_'.$status,
                    errorMessage: $error,
                );
            }

            return true;
        }

        // Unknown/non-final status — the job logs this and does not retry.
        Log::info('OCR: Textract completion deferred — non-final status.', [
            'uploaded_document_id' => $document->id,
            'tenant_id' => $document->tenant_id,
            'textract_job_id' => $jobId,
            'status' => $status,
        ]);

        return false;
    }
}
