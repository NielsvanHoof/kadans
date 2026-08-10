<?php

declare(strict_types=1);

use App\Clients\Models\Client;
use App\DocumentProcessing\Enums\DocumentProcessingStatus;
use App\DocumentProcessing\Transitions\UploadedDocumentProcessingTransitions;
use App\Dossiers\Models\DocumentRequest;
use App\Dossiers\Models\Dossier;
use App\Dossiers\Models\UploadedDocument;
use App\Tenancy\Models\Tenant;

covers(UploadedDocumentProcessingTransitions::class);

beforeEach(function (): void {
    $this->transitions = resolve(UploadedDocumentProcessingTransitions::class);
});

test('claim moves a pending document to processing for the expected path', function (): void {
    $document = createDocumentForProcessingTransitions();

    $claimed = $this->transitions->claim($document, $document->path);

    expect($claimed)->toBeInstanceOf(UploadedDocument::class)
        ->and($claimed->processing_status)->toBe(DocumentProcessingStatus::Processing)
        ->and($claimed->textract_job_id)->toBeNull()
        ->and($claimed->processing_started_at)->not->toBeNull();
});

test('claim refuses a completed document', function (): void {
    $document = createDocumentForProcessingTransitions([
        'processing_status' => DocumentProcessingStatus::Completed,
        'extracted_text' => 'done',
        'processing_finished_at' => now(),
    ]);

    expect($this->transitions->claim($document, $document->path))->toBeNull();
});

test('claim refuses an in-flight Textract job', function (): void {
    $document = createDocumentForProcessingTransitions([
        'processing_status' => DocumentProcessingStatus::Processing,
        'textract_job_id' => 'job-in-flight',
        'processing_started_at' => now(),
    ]);

    expect($this->transitions->claim($document, $document->path))->toBeNull()
        ->and($document->fresh()->textract_job_id)->toBe('job-in-flight');
});

test('claim refuses a stale path revision', function (): void {
    $document = createDocumentForProcessingTransitions();
    $stalePath = $document->path;

    $document->forceFill(['path' => 'uploads/replacement.pdf'])->save();

    expect($this->transitions->claim($document, $stalePath))->toBeNull()
        ->and($document->fresh()->processing_status)->toBe(DocumentProcessingStatus::Pending);
});

test('complete persists extracted text only while processing for the matching job', function (): void {
    $document = createDocumentForProcessingTransitions([
        'processing_status' => DocumentProcessingStatus::Processing,
        'textract_job_id' => 'job-1',
        'processing_started_at' => now(),
    ]);

    expect($this->transitions->complete($document, 'job-1', '{"fields":[]}'))->toBeTrue()
        ->and($document->fresh())
        ->processing_status->toBe(DocumentProcessingStatus::Completed)
        ->extracted_text->toBe('{"fields":[]}')
        ->and($this->transitions->complete($document->fresh(), 'job-1', 'ignored'))
        ->toBeFalse();
});

test('failWhileProcessing requires the matching job id', function (): void {
    $document = createDocumentForProcessingTransitions([
        'processing_status' => DocumentProcessingStatus::Processing,
        'textract_job_id' => 'job-1',
        'processing_started_at' => now(),
    ]);

    expect($this->transitions->failWhileProcessing($document, 'wrong-job', 'boom'))->toBeFalse()
        ->and($this->transitions->failWhileProcessing($document, 'job-1', 'boom'))->toBeTrue()
        ->and($document->fresh())
        ->processing_status->toBe(DocumentProcessingStatus::Failed)
        ->processing_error->toBe('boom');
});

test('failForPath marks permanent failure for the current path revision', function (): void {
    $document = createDocumentForProcessingTransitions([
        'processing_status' => DocumentProcessingStatus::Processing,
        'processing_started_at' => now(),
    ]);

    expect($this->transitions->failForPath($document, $document->path, 'exhausted'))->toBeTrue()
        ->and($document->fresh()->processing_status)->toBe(DocumentProcessingStatus::Failed)
        ->and($this->transitions->failForPath($document->fresh(), 'other-path.pdf', 'stale'))->toBeFalse();
});

/**
 * @param  array<string, mixed>  $overrides
 */
function createDocumentForProcessingTransitions(array $overrides = []): UploadedDocument
{
    $tenant = Tenant::factory()->create();
    $tenant->makeCurrent();

    $client = Client::factory()->create(['tenant_id' => $tenant->id]);
    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
    ]);
    $documentRequest = DocumentRequest::factory()->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $dossier->id,
    ]);

    return UploadedDocument::factory()->create([
        'tenant_id' => $tenant->id,
        'document_request_id' => $documentRequest->id,
        'path' => 'uploads/statement.pdf',
        ...$overrides,
    ]);
}
