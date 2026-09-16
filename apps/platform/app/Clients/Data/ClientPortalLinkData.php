<?php

declare(strict_types=1);

namespace App\Clients\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One standing portal link, as the client page shows it.
 *
 * Owned by Clients rather than ClientPortal so the contract that produces it
 * can point downward: Clients (L4) must not reference ClientPortal (L5), which
 * is what an arch test enforces. The token is not here and never will be — the
 * plaintext exists once, in the response to the request that created it.
 */
#[TypeScript]
final class ClientPortalLinkData extends Data
{
    public function __construct(
        public readonly int $id,
        public readonly string $expiresAt,
        public readonly ?string $revokedAt,
        public readonly ?string $lastUsedAt,
        public readonly bool $isValid,
        /** How many dossiers this link currently opens. */
        public readonly int $sharedDossierCount,
    ) {}
}
