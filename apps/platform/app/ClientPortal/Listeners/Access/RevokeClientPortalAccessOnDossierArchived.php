<?php

declare(strict_types=1);

namespace App\ClientPortal\Listeners\Access;

use App\ClientPortal\Actions\Access\RevokeClientPortalAccessAction;
use App\ClientPortal\Enums\ClientAccessGrantScope;
use App\ClientPortal\Models\ClientAccessGrant;
use App\Dossiers\Events\Lifecycle\DossierArchived;

/**
 * Archiving a dossier must invalidate any live magic-link access to it.
 *
 * Deliberately synchronous: this runs inside the archiving transaction, so a
 * failure to revoke aborts the archive rather than leaving the portal open.
 */
final readonly class RevokeClientPortalAccessOnDossierArchived
{
    public function __construct(
        private RevokeClientPortalAccessAction $revokeClientPortalAccess,
    ) {}

    public function handle(DossierArchived $event): void
    {
        // Scope is explicit rather than implied by the dossier_id match. A
        // client-scoped grant has a null dossier_id so it would not match
        // anyway, but archiving one dossier of several must visibly not kill the
        // client's standing link — that is a decision, not a side effect of how
        // NULL compares.
        $query = ClientAccessGrant::query();
        $query->getQuery()
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->where('scope', ClientAccessGrantScope::Dossier->value)
            ->where('dossier_id', $event->dossier->getKey());

        $query->get()
            ->each(function (ClientAccessGrant $grant) use ($event): void {
                $this->revokeClientPortalAccess->handle($grant, $event->archivedBy);
            });
    }
}
