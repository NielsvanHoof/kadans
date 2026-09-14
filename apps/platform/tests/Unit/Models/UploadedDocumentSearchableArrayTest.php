<?php

declare(strict_types=1);

use App\DocumentProcessing\Enums\DocumentProcessingStatus;
use App\Dossiers\Models\UploadedDocument;

covers(UploadedDocument::class);

test('uploaded document scout columns omit integer id so postgres ilike cannot error', function (): void {
    $document = new UploadedDocument([
        'original_filename' => 'leasecontract-bestelbus.pdf',
        'searchable_text' => null,
    ]);

    expect($document->toSearchableArray())->toBe([
        'original_filename' => 'leasecontract-bestelbus.pdf',
        'searchable_text' => '',
    ]);
});

test('pending uploads are not searchable so create does not call titan', function (DocumentProcessingStatus $status): void {
    $document = new UploadedDocument([
        'original_filename' => 'payslip.pdf',
        'processing_status' => $status,
    ]);

    expect($document->shouldBeSearchable())->toBeFalse();
})->with([
    'pending' => DocumentProcessingStatus::Pending,
    'processing' => DocumentProcessingStatus::Processing,
    'failed' => DocumentProcessingStatus::Failed,
]);

test('completed uploads are searchable after ocr writes the overview', function (): void {
    $document = new UploadedDocument([
        'original_filename' => 'payslip.pdf',
        'processing_status' => DocumentProcessingStatus::Completed,
    ]);

    expect($document->shouldBeSearchable())->toBeTrue();
});
