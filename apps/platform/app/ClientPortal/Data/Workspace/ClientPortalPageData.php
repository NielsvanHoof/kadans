<?php

declare(strict_types=1);

namespace App\ClientPortal\Data\Workspace;

use App\ClientPortal\Data\Session\PortalSessionData;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Full client portal Inertia payload for one dossier.
 */
#[TypeScript]
final class ClientPortalPageData extends Data
{
    /**
     * @param  list<PortalDossierSummaryData>  $otherDossiers  Empty whenever this
     *                                                         link opens only the
     *                                                         dossier being shown,
     *                                                         which is every
     *                                                         dossier-scoped grant.
     */
    public function __construct(
        public readonly PortalFirmData $firm,
        public readonly PortalDossierData $dossier,
        public readonly PortalSessionData $session,
        public readonly array $otherDossiers = [],
    ) {}
}
