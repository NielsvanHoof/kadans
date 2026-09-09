<?php

declare(strict_types=1);

namespace App\Reporting\Data\MediaLibrary;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Uploaded document nested in a media library row.
 */
#[TypeScript]
final class MediaLibraryUploadedDocumentData extends Data
{
    public function __construct(
        public readonly int $id,
        public readonly string $originalFilename,
        public readonly int $sizeBytes,
        public readonly string $uploadedAt,
    ) {}
}
