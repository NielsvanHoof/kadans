<?php

declare(strict_types=1);

namespace App\Reporting\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Dossier summary nested in a media library row.
 */
#[TypeScript]
final class MediaLibraryDossierSummaryData extends Data
{
    public function __construct(
        public readonly int $id,
        public readonly string $title,
        public readonly ?string $reference,
    ) {}
}
