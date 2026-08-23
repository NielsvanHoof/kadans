<?php

declare(strict_types=1);

use App\Dossiers\Support\DocumentPresigner;
use Illuminate\Support\Facades\Storage;

covers(DocumentPresigner::class);

beforeEach(function (): void {
    Storage::fake('s3');
    config(['filesystems.default' => 's3']);
});

test('moveObject relocates a staged upload without reading object acls', function (): void {
    $source = 'staging/tenants/1/01TEST.pdf';
    $destination = 'tenants/1/dossiers/2/01TEST.pdf';

    Storage::disk('s3')->put($source, 'pdf-bytes');

    resolve(DocumentPresigner::class)->moveObject('s3', $source, $destination);

    Storage::disk('s3')->assertMissing($source);
    Storage::disk('s3')->assertExists($destination);
});
