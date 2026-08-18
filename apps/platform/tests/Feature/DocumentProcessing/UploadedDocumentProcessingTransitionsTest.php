<?php

declare(strict_types=1);

use App\Clients\Models\Client;
use App\DocumentProcessing\Enums\DocumentProcessingStatus;
use App\DocumentProcessing\Transitions\UploadedDocumentProcessingTransitions;
use App\Dossiers\Models\DocumentRequest;
use App\Dossiers\Models\Dossier;
use App\Dossiers\Models\UploadedDocument;

covers(UploadedDocumentProcessingTransitions::class);

beforeEach(function (): void {
    ['tenant' => $tenant] = provisionWorkspace();
    $this->tenant = $tenant;
    $this->transitions = resolve(UploadedDocumentProcessingTransitions::class);
});

/**
 * @param  array<string, mixed>  $overrides
 */
function createDocumentForProcessingTransitions(array $overrides = []): UploadedDocument
{
    $tenant = test()->tenant;
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

describe('claim', function (): void {
    test('moves a pending document to processing for the expected path', function (): void {
        $document = createDocumentForProcessingTransitions();

        $claimed = $this->transitions->claim($document, $document->path);

        expect($claimed)->toBeInstanceOf(UploadedDocument::class)
            ->and($claimed->processing_status)->toBe(DocumentProcessingStatus::Processing)
            ->and($claimed->textract_job_id)->toBeNull()
            ->and($claimed->processing_started_at)->not->toBeNull();
    });

    test('refuses a document that cannot be claimed', function (array $overrides): void {
        $document = createDocumentForProcessingTransitions($overrides);

        expect($this->transitions->claim($document, $document->path))->toBeNull();
    })->with([
        'completed' => [[
            'processing_status' => DocumentProcessingStatus::Completed,
            'extracted_text' => 'done',
        ]],
        'in-flight Textract job' => [[
            'processing_status' => DocumentProcessingStatus::Processing,
            'textract_job_id' => 'job-in-flight',
        ]],
    ]);

    test('refuses a stale path revision', function (): void {
        $document = createDocumentForProcessingTransitions();
        $stalePath = $document->path;

        $document->forceFill(['path' => 'uploads/replacement.pdf'])->save();

        expect($this->transitions->claim($document, $stalePath))->toBeNull()
            ->and($document->fresh()->processing_status)->toBe(DocumentProcessingStatus::Pending);
    });
});

describe('complete', function (): void {
    test('persists extracted text only while processing for the matching job', function (): void {
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
});

describe('fail', function (): void {
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
});
