<?php

declare(strict_types=1);

use App\Clients\Models\Client;
use App\DocumentProcessing\Enums\DocumentProcessingStatus;
use App\DocumentProcessing\Services\Ocr\FlattenExtractedTextForSearch;
use App\DocumentProcessing\Transitions\Ocr\UploadedDocumentProcessingTransitions;
use App\Dossiers\Models\DocumentRequest;
use App\Dossiers\Models\Dossier;
use App\Dossiers\Models\UploadedDocument;
use App\Tenancy\Models\Tenant;

covers(UploadedDocumentProcessingTransitions::class, FlattenExtractedTextForSearch::class);

function salaryExtractionJson(): string
{
    return json_encode([
        'document_type' => 'bank_statement',
        'summary' => 'Monthly salary payments for March.',
        'fields' => [
            [
                'label' => 'IBAN',
                'value' => 'NL18 RABO 0123 4567 89',
                'confidence' => 0.99,
                'sensitivity' => 'iban',
            ],
            [
                'label' => 'BSN',
                'value' => '123456789',
                'confidence' => 0.91,
                'sensitivity' => 'bsn',
            ],
        ],
        'tables' => [],
        'notes' => ['Salary credited on the first.'],
        'confidence' => 0.96,
    ], JSON_THROW_ON_ERROR);
}

/**
 * @return array{tenant: Tenant, document: UploadedDocument}
 */
function createCompletedSearchDocument(string $extractedJson, string $filename = 'statement.pdf'): array
{
    ['tenant' => $tenant] = provisionWorkspace();
    $client = Client::factory()->create(['tenant_id' => $tenant->id]);
    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
        'title' => 'Payroll 2026',
    ]);
    $request = DocumentRequest::factory()->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $dossier->id,
        'title' => 'Bank statement',
    ]);
    $document = UploadedDocument::factory()->processed($extractedJson)->create([
        'tenant_id' => $tenant->id,
        'document_request_id' => $request->id,
        'original_filename' => $filename,
    ]);

    return ['tenant' => $tenant, 'document' => $document];
}

test('ocr complete query update still writes searchable text and indexes', function (): void {
    ['tenant' => $tenant] = provisionWorkspace();
    $client = Client::factory()->create(['tenant_id' => $tenant->id]);
    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
    ]);
    $request = DocumentRequest::factory()->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $dossier->id,
    ]);
    $document = UploadedDocument::factory()->processing()->create([
        'tenant_id' => $tenant->id,
        'document_request_id' => $request->id,
        'original_filename' => 'payslip.pdf',
        'textract_job_id' => 'job-index-1',
    ]);

    $updated = resolve(UploadedDocumentProcessingTransitions::class)->complete(
        $document,
        'job-index-1',
        salaryExtractionJson(),
    );

    expect($updated)->toBeTrue();

    $document->refresh();

    expect($document->processing_status)->toBe(DocumentProcessingStatus::Completed)
        ->and($document->searchable_text)->toContain('NL18RABO0123456789')
        ->and(UploadedDocument::search('NL18RABO0123456789')->keys()->all())
        ->toContain($document->id);
});

test('keyword search finds a compact iban on the collection driver', function (): void {
    ['document' => $document] = createCompletedSearchDocument(salaryExtractionJson());

    expect(config('scout.driver'))->toBe('collection')
        ->and(UploadedDocument::search('NL18RABO0123456789')->keys()->all())
        ->toContain($document->id);
});

test('to searchable embedding contains overview and not identifiers', function (): void {
    ['document' => $document] = createCompletedSearchDocument(salaryExtractionJson());

    $source = $document->toSearchableEmbedding();

    expect($source)->toContain('bank_statement')
        ->and($source)->toContain('Monthly salary payments for March.')
        ->and($source)->not->toContain('NL18')
        ->and($source)->not->toContain('123456789');
});
