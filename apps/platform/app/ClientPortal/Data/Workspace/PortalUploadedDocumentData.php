<?php

declare(strict_types=1);

namespace App\ClientPortal\Data\Workspace;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Uploaded file shown to the client on the portal.
 */
#[TypeScript]
final class PortalUploadedDocumentData extends Data
{
    public function __construct(
        public readonly int $id,
        public readonly string $originalFilename,
        public readonly int $sizeBytes,
        public readonly string $uploadedAt,
    ) {}
}
