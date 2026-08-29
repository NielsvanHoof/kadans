<?php

declare(strict_types=1);

use App\DocumentProcessing\Enums\DocumentProcessingStatus;
use App\DocumentProcessing\Services\Ocr\Drivers\DemoExtractionSamples;
use App\DocumentProcessing\Services\Ocr\Drivers\DemoOcrExtractor;
use App\Dossiers\Models\UploadedDocument;
use App\Tenancy\Models\Tenant;

covers(DemoOcrExtractor::class);

beforeEach(function (): void {
    // The pause exists for a live audience; it would only slow the suite down.
    config(['ocr.demo.delay_seconds' => 0]);
});

test('it writes a recognisable extraction for a known document kind', function (): void {
    $document = UploadedDocument::factory()->processing()->create([
        'original_filename' => 'loonstroken-augustus.pdf',
    ]);

    // The driver's compare-and-swap runs through the tenant scope, exactly as it
    // does inside the tenant-aware OCR job.
    Tenant::query()->findOrFail($document->tenant_id)->makeCurrent();

    resolve(DemoOcrExtractor::class)->start($document);

    $document->refresh();

    expect($document->processing_status)->toBe(DocumentProcessingStatus::Completed);

    /** @var array<string, mixed> $payload */
    $payload = json_decode((string) $document->extracted_text, true, 512, JSON_THROW_ON_ERROR);

    expect($payload['document_type'])->toBe('loonstrook')
        ->and($payload['fields'])->not->toBeEmpty();
});

test('it masks a BSN so the sensitivity treatment is demonstrable', function (): void {
    /** @var array<string, mixed> $payload */
    $payload = json_decode(DemoExtractionSamples::forTitle('Loonstroken augustus'), true, 512, JSON_THROW_ON_ERROR);

    $sensitivities = array_column($payload['fields'], 'sensitivity');

    expect($sensitivities)->toContain('bsn');
});

test('an unrecognised filename still produces a renderable extraction', function (): void {
    /** @var array<string, mixed> $payload */
    $payload = json_decode(DemoExtractionSamples::forTitle('willekeurig-bestand'), true, 512, JSON_THROW_ON_ERROR);

    expect($payload['document_type'])->toBe('overig')
        ->and($payload['fields'])->not->toBeEmpty()
        ->and($payload['summary'])->toContain('willekeurig-bestand');
});

test('it discards a result whose upload was replaced mid-run', function (): void {
    $document = UploadedDocument::factory()->processing()->create([
        'original_filename' => 'bankafschriften.pdf',
    ]);

    Tenant::query()->findOrFail($document->tenant_id)->makeCurrent();

    $stale = $document->replicate();
    $stale->id = $document->id;
    $stale->path = 'tenants/1/dossiers/1/an-older-revision.pdf';

    resolve(DemoOcrExtractor::class)->start($stale);

    expect($document->refresh()->processing_status)->toBe(DocumentProcessingStatus::Processing);
});
