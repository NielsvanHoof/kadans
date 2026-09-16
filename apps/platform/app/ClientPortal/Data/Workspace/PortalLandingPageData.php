<?php

declare(strict_types=1);

namespace App\ClientPortal\Data\Workspace;

use App\ClientPortal\Data\Session\PortalSessionData;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Everything one client owes their firm, across dossiers.
 *
 * Rendered only for a client-scoped link with more than one dossier behind it —
 * or none, which is a real and calm state rather than an error.
 */
#[TypeScript]
final class PortalLandingPageData extends Data
{
    /**
     * @param  list<PortalDossierSummaryData>  $waiting  Still needs the client.
     * @param  list<PortalDossierSummaryData>  $completed  Readable, nothing owed.
     */
    public function __construct(
        public readonly PortalFirmData $firm,
        public readonly PortalClientData $client,
        public readonly PortalSessionData $session,
        public readonly array $waiting,
        public readonly array $completed,
    ) {}
}
