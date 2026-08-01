<?php

declare(strict_types=1);

namespace App\Dossiers\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Portal access-grant row on dossier show.
 */
#[TypeScript]
final class DossierAccessGrantData extends Data
{
    public function __construct(
        public readonly int $id,
        public readonly string $expiresAt,
        public readonly ?string $revokedAt,
        public readonly ?string $lastUsedAt,
        public readonly bool $isValid,
    ) {}
}
