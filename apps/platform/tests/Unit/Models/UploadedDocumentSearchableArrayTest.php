<?php

declare(strict_types=1);

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
