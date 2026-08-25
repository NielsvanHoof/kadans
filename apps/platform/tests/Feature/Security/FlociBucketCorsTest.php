<?php

declare(strict_types=1);

/**
 * Browser PUTs to Floci are cross-origin (http://localhost → :4566). The
 * bucket CORS rule in ready.d is what answers the OPTIONS preflight — without
 * it Safari/Chrome fail the signed upload with 403.
 */
test('the Floci bootstrap applies PUT cors for the Sail browser origin', function (): void {
    $script = (string) file_get_contents(base_path('docker/floci/init/ready.d/01-create-local-bucket.sh'));

    expect($script)
        ->toContain('put-bucket-cors')
        ->toContain('"AllowedMethods": ["PUT"]')
        ->toContain('"http://localhost"')
        ->toContain('"http://127.0.0.1"')
        ->toContain('"AllowedHeaders": ["content-type"]')
        ->toContain('"ExposeHeaders": ["ETag"]')
        ->not->toContain('*');
});
