<?php

declare(strict_types=1);

namespace App\Dossiers\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Client name/email embedded on dossier show.
 */
#[TypeScript]
final class DossierClientSummaryData extends Data
{
    public function __construct(
        public readonly string $name,
        public readonly string $email,
    ) {}
}
