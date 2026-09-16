<?php

declare(strict_types=1);

namespace App\Clients\Contracts;

use App\Accounts\Models\User;
use App\Clients\Data\ClientPortalLinkData;
use App\Clients\Models\Client;

/**
 * Standing portal links for a client, for the client page to render.
 *
 * Inverted for layering: the client page needs to show portal links and whether
 * this member may manage them, but Clients (L4) cannot import ClientPortal (L5)
 * — not the model, not the policy. Clients owns the question and the shape of
 * the answer; ClientPortal binds the implementation, exactly as Dossiers does
 * with ClientPortalReminders.
 */
interface ClientPortalAccess
{
    /**
     * Live links first, then recent history.
     *
     * @return list<ClientPortalLinkData>
     */
    public function linksFor(Client $client): array;

    /** May this member issue a standing link for this client? */
    public function canIssue(?User $user): bool;

    /** May this member revoke one? */
    public function canRevoke(?User $user): bool;
}
