<?php

declare(strict_types=1);

use App\Audit\Actions\LogAuditActivityAction;
use App\Clients\Models\Client;
use App\DocumentProcessing\Contracts\Ocr\StartsDocumentOcr;
use App\DocumentProcessing\Enums\DocumentProcessingStatus;
use App\DocumentProcessing\Jobs\ProcessUploadedDocumentJob;
use App\DocumentProcessing\Services\Ocr\OcrOrchestrator;
use App\DocumentProcessing\Transitions\UploadedDocumentProcessingTransitions;
use App\Dossiers\Models\DocumentRequest;
use App\Dossiers\Models\Dossier;
use App\Dossiers\Models\UploadedDocument;
use App\Pipelines\Support\PipelineState;
use Illuminate\Support\Facades\Storage;

covers(UploadedDocumentProcessingTransitions::class, ProcessUploadedDocumentJob::class);

beforeEach(function (): void {
    Storage::fake('local');
    config(['ocr.driver' => 'mock']);
});

/**
 * Bind a driver that reaches the provider — recording a job id — and only then
 * fails. That ordering is the whole point: a failure BEFORE the provider is
 * reached leaves textract_job_id null, which claim() already tolerates.
 */
function bindOcrDriverThatFailsAfterSubmitting(): void
{
    app()->bind(StartsDocumentOcr::class, fn (): StartsDocumentOcr => new class implements StartsDocumentOcr
    {
        public function start(UploadedDocument $document): void
        {
            UploadedDocument::query()
                ->whereKey($document->id)
                ->update(['textract_job_id' => 'textract-job-abc']);

            throw new RuntimeException('Textract accepted the job, then the worker died.');
        }
    });
}

function retryFixtureDocument(): UploadedDocument
{
    ['tenant' => $tenant] = provisionWorkspace();

    $client = Client::factory()->create(['tenant_id' => $tenant->id]);
    $dossier = Dossier::factory()->create(['tenant_id' => $tenant->id, 'client_id' => $client->id]);
    $documentRequest = DocumentRequest::factory()->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $dossier->id,
    ]);

    $path = sprintf('tenants/%d/dossiers/%d/scan.pdf', $tenant->id, $dossier->id);
    Storage::disk('local')->put($path, 'fake-pdf-bytes');

    return UploadedDocument::factory()->create([
        'tenant_id' => $tenant->id,
        'document_request_id' => $documentRequest->id,
        'disk' => 'local',
        'path' => $path,
    ]);
}

function runOcrJob(UploadedDocument $document): void
{
    new ProcessUploadedDocumentJob($document->id, $document->path)->handle(
        resolve(OcrOrchestrator::class),
        resolve(LogAuditActivityAction::class),
        resolve(PipelineState::class),
        resolve(UploadedDocumentProcessingTransitions::class),
    );
}

/**
 * A retryable failure that already reached Textract used to leave the document
 * on "Processing" with a job id, which claim() reads as "an external job is
 * already in flight" — so every later attempt declined to claim, returned
 * successfully, and the exhausted-failure branch was never reached. Nothing
 * recovered it: no scheduled OCR sweep exists, and StuckPipelinesCheck watches
 * Pipeline rows, not processing_status. The document span forever with no
 * error text and no DocumentProcessingFailed audit entry.
 *
 * AudioTranscriptionTransitions::resetForRetry() already solved this for the
 * transcription path.
 */
test('a retryable failure after the provider was reached leaves the document reclaimable', function (): void {
    $uploaded = retryFixtureDocument();

    bindOcrDriverThatFailsAfterSubmitting();

    expect(fn () => runOcrJob($uploaded))->toThrow(RuntimeException::class);

    $uploaded->refresh();

    // Reset for the next attempt rather than left mid-flight.
    expect($uploaded->processing_status)->toBe(DocumentProcessingStatus::Pending)
        ->and($uploaded->textract_job_id)->toBeNull();

    // And the next attempt can genuinely claim it.
    expect(resolve(UploadedDocumentProcessingTransitions::class)->claim($uploaded, $uploaded->path))
        ->toBeInstanceOf(UploadedDocument::class);
});

test('the reset only touches the revision the job was working on', function (): void {
    $uploaded = retryFixtureDocument();

    $staleJob = new ProcessUploadedDocumentJob($uploaded->id, 'tenants/1/dossiers/1/replaced.pdf');

    resolve(UploadedDocumentProcessingTransitions::class)->claim($uploaded, $uploaded->path);

    // A job holding a replaced path must not reset the current revision.
    resolve(UploadedDocumentProcessingTransitions::class)
        ->resetForRetry($uploaded, 'tenants/1/dossiers/1/replaced.pdf');

    expect($uploaded->refresh()->processing_status)->toBe(DocumentProcessingStatus::Processing)
        ->and($staleJob->expectedPath)->toBe('tenants/1/dossiers/1/replaced.pdf');
});
