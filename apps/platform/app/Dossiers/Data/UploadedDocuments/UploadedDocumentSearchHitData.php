<?php

declare(strict_types=1);

namespace App\Dossiers\Data\UploadedDocuments;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One uploaded-document hit from keyword / hybrid search.
 *
 * Overview fields only — never OCR excerpts — so the palette cannot echo IBANs.
 */
#[TypeScript]
final class UploadedDocumentSearchHitData extends Data
{
    public function __construct(
        public readonly int $id,
        public readonly string $originalFilename,
        public readonly ?string $documentType,
        public readonly ?string $summary,
        public readonly string $requestTitle,
        public readonly int $dossierId,
        public readonly string $dossierTitle,
        public readonly string $clientName,
        public readonly string $showUrl,
    ) {}
}
