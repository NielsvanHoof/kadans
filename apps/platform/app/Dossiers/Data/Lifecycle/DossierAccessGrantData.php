<?php

declare(strict_types=1);

namespace App\Dossiers\Data\Lifecycle;

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
        /**
         * A standing link for the whole client rather than this dossier. Managed
         * on the client's page; shown here so revoking is not misread.
         */
        public readonly bool $isClientLevel = false,
    ) {}
}
