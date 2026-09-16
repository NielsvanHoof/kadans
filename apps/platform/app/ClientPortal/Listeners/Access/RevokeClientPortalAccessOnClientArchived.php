<?php

declare(strict_types=1);

namespace App\ClientPortal\Listeners\Access;

use App\ClientPortal\Actions\Access\RevokeClientPortalAccessAction;
use App\ClientPortal\Models\ClientAccessGrant;
use App\Clients\Events\ClientArchived;

/**
 * Archiving a client closes the standing link into their records.
 *
 * Not left to fall out of the reachable set. A client-scoped grant survives its
 * client being archived — every dossier simply drops away — and would come back
 * to life the moment someone restored the client, months later, still inside its
 * original expiry. Revoking is the only thing that actually ends it.
 *
 * Dossier-scoped grants are already handled by
 * RevokeClientPortalAccessOnDossierArchived, which DeleteClientAction triggers
 * for each dossier before it gets here.
 */
final readonly class RevokeClientPortalAccessOnClientArchived
{
    public function __construct(
        private RevokeClientPortalAccessAction $revokeClientPortalAccess,
    ) {}

    public function handle(ClientArchived $event): void
    {
        $query = ClientAccessGrant::query();
        $query->getQuery()
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->where('client_id', $event->client->getKey());

        $query->get()
            ->each(function (ClientAccessGrant $grant) use ($event): void {
                $this->revokeClientPortalAccess->handle($grant, $event->archivedBy);
            });
    }
}
