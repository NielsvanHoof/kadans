<?php

declare(strict_types=1);

namespace App\DocumentProcessing\Actions\Ocr;

use App\DocumentProcessing\Actions\Pipelines\PipelineStateAction;
use App\DocumentProcessing\Enums\DocumentProcessingStatus;
use App\DocumentProcessing\Enums\PipelineStepOperation;
use App\DocumentProcessing\Models\Pipeline;
use App\Dossiers\Models\UploadedDocument;
use App\Tenancy\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Log;
use Throwable;

use function in_array;

/**
 * Mark a Textract job as permanently failed after SQS receive exhaustion.
 */
final readonly class FailTextractExtractionAction
{
    public function __construct(
        private MarkTextractExtractionFailedAction $markTextractExtractionFailed,
        private PipelineStateAction $pipelineState,
        private ResolveOcrPipelineForDocumentAction $resolveOcrPipeline,
        private PrepareOcrPipelineForProviderResultAction $prepareOcrPipeline,
        private TenantContext $tenantContext,
    ) {}

    public function handle(string $jobId, Throwable $exception): bool
    {
        $document = UploadedDocument::query()
            ->withoutTenantScope()
            ->where('textract_job_id', $jobId)
            ->first();

        if (! $document instanceof UploadedDocument) {
            Log::warning('OCR: permanent completion failure has no matching uploaded document.', [
                'textract_job_id' => $jobId,
            ]);

            return false;
        }

        $tenant = Tenant::query()->find($document->tenant_id);

        if (! $tenant instanceof Tenant) {
            Log::error('OCR: permanent completion failure document has missing tenant.', [
                'uploaded_document_id' => $document->id,
                'tenant_id' => $document->tenant_id,
                'textract_job_id' => $jobId,
            ]);

            return false;
        }

        return $this->tenantContext->runForTenant($tenant, function () use ($document, $exception, $jobId): bool {
            $pipeline = $this->resolveOcrPipeline->handle($document, $jobId);

            if (in_array($document->processing_status, [
                DocumentProcessingStatus::Completed,
                DocumentProcessingStatus::Failed,
            ], true)) {
                return true;
            }

            $error = mb_substr($exception->getMessage(), 0, 500);

            if ($error === '') {
                $error = 'Textract result processing failed permanently.';
            }

            $this->markTextractExtractionFailed->handle(
                $document,
                $jobId,
                $error,
                'RESULT_PROCESSING_FAILED',
            );

            if ($pipeline instanceof Pipeline) {
                $pipeline = $this->prepareOcrPipeline->handle(
                    $pipeline,
                    (string) config('ocr.driver'),
                    $jobId,
                );
                $this->pipelineState->handle(
                    $pipeline,
                    PipelineStepOperation::Fail,
                    $pipeline->current_stage,
                    errorCode: 'result_processing_failed',
                    errorMessage: $error,
                );
            }

            return true;
        });
    }
}
