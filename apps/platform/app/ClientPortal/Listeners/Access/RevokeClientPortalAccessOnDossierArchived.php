<?php

declare(strict_types=1);

namespace App\ClientPortal\Listeners\Access;

use App\ClientPortal\Actions\Access\RevokeClientPortalAccessAction;
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
        ClientAccessGrant::query()
            ->where('dossier_id', $event->dossier->getKey())
            ->where('revoked_at', null)
            ->where('expires_at', '>', now())
            ->get()
            ->each(function (ClientAccessGrant $grant) use ($event): void {
                $this->revokeClientPortalAccess->handle($grant, $event->archivedBy);
            });
    }
}
