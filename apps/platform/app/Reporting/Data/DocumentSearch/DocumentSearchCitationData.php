<?php

declare(strict_types=1);

namespace App\Reporting\Data\DocumentSearch;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class DocumentSearchCitationData extends Data
{
    public function __construct(
        public readonly int $uploadedDocumentId,
        public readonly string $why,
        public readonly string $originalFilename,
        public readonly string $showUrl,
    ) {}
}
