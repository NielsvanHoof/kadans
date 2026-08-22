<?php

declare(strict_types=1);

namespace App\ClientPortal\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Full client portal Inertia payload.
 */
#[TypeScript]
final class ClientPortalPageData extends Data
{
    public function __construct(
        public readonly PortalFirmData $firm,
        public readonly PortalDossierData $dossier,
        public readonly PortalSessionData $session,
    ) {}
}
