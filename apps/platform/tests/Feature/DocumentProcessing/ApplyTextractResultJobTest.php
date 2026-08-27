<?php

declare(strict_types=1);

use App\Clients\Models\Client;
use App\DocumentProcessing\Actions\Ocr\CompleteTextractExtractionAction;
use App\DocumentProcessing\Enums\DocumentProcessingStatus;
use App\DocumentProcessing\Jobs\ApplyTextractResultJob;
use App\Dossiers\Models\DocumentRequest;
use App\Dossiers\Models\Dossier;
use App\Dossiers\Models\UploadedDocument;
use App\Pipelines\Actions\CreatePipelineAction;
use App\Pipelines\Enums\PipelineStatus;
use App\Pipelines\Enums\PipelineStepKey;
use App\Pipelines\Enums\PipelineType;
use App\Pipelines\Support\PipelineState;

covers(ApplyTextractResultJob::class);

function documentAwaitingTextract(string $jobId): UploadedDocument
{
    ['tenant' => $tenant] = provisionWorkspace();

    $client = Client::factory()->create(['tenant_id' => $tenant->id]);
    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
    ]);
    $documentRequest = DocumentRequest::factory()->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $dossier->id,
    ]);

    return UploadedDocument::factory()->processing()->create([
        'tenant_id' => $tenant->id,
        'document_request_id' => $documentRequest->id,
        'textract_job_id' => $jobId,
    ]);
}

test('exhausted retries mark the document failed rather than leaving it processing', function (): void {
    $document = documentAwaitingTextract('job-exhausted');

    // This is the terminal path the consumer used to reach by counting SQS
    // receives. It now belongs to the job, so a document cannot sit in
    // `processing` forever just because the result could not be applied.
    new ApplyTextractResultJob(
        ['JobId' => 'job-exhausted', 'Status' => 'SUCCEEDED', 'API' => 'StartDocumentAnalysis'],
        'job-exhausted',
    )->failed(new RuntimeException('Persistent Textract result failure.'));

    $document->refresh();

    expect($document->processing_status)->toBe(DocumentProcessingStatus::Failed)
        ->and($document->processing_error)->toBe('Persistent Textract result failure.')
        ->and($document->processing_finished_at)->not->toBeNull();
});

test('exhausted retries close the pipeline instead of leaving it for the stuck monitor', function (): void {
    $document = documentAwaitingTextract('job-pipeline-exhausted');

    // Reproduces what staging showed on 2026-08-27: the failed_jobs row landed,
    // the document was marked failed, and the run still sat in `running` until
    // pipelines:monitor marked it `stuck` 30 minutes later. `stuck` is a status
    // pipelines:retry-due never picks up, so the run became a dead end.
    $pipeline = resolve(CreatePipelineAction::class)->handle($document, PipelineType::OcrDocument);
    $state = resolve(PipelineState::class);
    $state->start($pipeline, PipelineStepKey::Submit);
    $state->complete($pipeline, PipelineStepKey::Submit);
    $state->start($pipeline, PipelineStepKey::ProviderProcessing);
    $state->complete($pipeline, PipelineStepKey::ProviderProcessing);
    $state->start($pipeline, PipelineStepKey::ProcessResults);

    new ApplyTextractResultJob(
        ['JobId' => 'job-pipeline-exhausted', 'Status' => 'SUCCEEDED', 'API' => 'StartDocumentAnalysis'],
        'job-pipeline-exhausted',
    )->failed(new RuntimeException('Persistent Textract result failure.'));

    $pipeline->refresh();

    expect($pipeline->status)->toBe(PipelineStatus::Failed)
        ->and($pipeline->error_code)->toBe('result_processing_failed');
});

test('a notification nothing can act on is logged, not retried', function (): void {
    $document = documentAwaitingTextract('job-live');

    // A job id no document claims. Running the same payload again cannot change
    // that, so it must not throw — a retry would only burn the attempt budget
    // and end in a failed_jobs row beside real failures.
    new ApplyTextractResultJob(
        ['JobId' => 'job-that-never-existed', 'Status' => 'SUCCEEDED', 'API' => 'StartDocumentAnalysis'],
        'job-that-never-existed',
    )->handle(resolve(CompleteTextractExtractionAction::class));

    $document->refresh();

    expect($document->processing_status)->toBe(DocumentProcessingStatus::Processing);
});

test('the unique lock releases after the worst-case attempt budget', function (): void {
    $job = new ApplyTextractResultJob(['JobId' => 'job-unique'], 'job-unique');

    // SNS delivers at least once, so the key is the provider job id — but a
    // SIGKILL must not wedge that id forever.
    expect($job->uniqueId())->toBe('job-unique')
        ->and($job->uniqueFor())->toBe(
            (ApplyTextractResultJob::TIMEOUT_SECONDS * ApplyTextractResultJob::MAX_TRIES)
            + array_sum(ApplyTextractResultJob::BACKOFF_SECONDS),
        )
        // Must stay under the connection's retry_after, or the queue re-reserves
        // a job that is still running.
        ->and($job->uniqueFor())->toBeLessThan(config('queue.connections.redis.retry_after'));
});
