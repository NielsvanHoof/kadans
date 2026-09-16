<?php

declare(strict_types=1);

namespace App\ClientPortal\Support\Access;

use App\Accounts\Models\User;
use App\ClientPortal\Enums\ClientAccessGrantScope;
use App\ClientPortal\Models\ClientAccessGrant;
use App\ClientPortal\Queries\Access\FetchReachableDossiersQuery;
use App\Clients\Contracts\ClientPortalAccess;
use App\Clients\Data\ClientPortalLinkData;
use App\Clients\Models\Client;

use function array_values;

/**
 * Standing client links, for the client page.
 *
 * Only client-scoped grants: the per-dossier links belong to their dossier's
 * own access panel, and listing them here would read as "these all open the
 * client" when they do not.
 */
final readonly class ClientPortalAccessProvider implements ClientPortalAccess
{
    /** Enough to show what happened recently without paging. */
    private const int HISTORY_LIMIT = 20;

    public function __construct(private FetchReachableDossiersQuery $fetchReachableDossiers) {}

    /**
     * @return list<ClientPortalLinkData>
     */
    public function linksFor(Client $client): array
    {
        $query = ClientAccessGrant::query();
        $query->getQuery()
            ->where('scope', ClientAccessGrantScope::Client->value)
            ->where('client_id', $client->getKey())
            ->orderByDesc('id')
            ->limit(self::HISTORY_LIMIT);

        $grants = $query->get();

        return array_values($grants->map(fn (ClientAccessGrant $grant): ClientPortalLinkData => new ClientPortalLinkData(
            id: $grant->id,
            expiresAt: $grant->expires_at->toIso8601String(),
            revokedAt: $grant->revoked_at?->toIso8601String(),
            lastUsedAt: $grant->last_used_at?->toIso8601String(),
            isValid: $grant->isValid(),
            // Counted through the same query the portal itself uses, so the
            // number staff see is the number of dossiers the link opens — not a
            // second, drifting definition of reach.
            sharedDossierCount: $grant->isValid()
                ? $this->fetchReachableDossiers->handle($grant)->count()
                : 0,
        ))->all());
    }

    public function canIssue(?User $user): bool
    {
        return $user?->can('create', ClientAccessGrant::class) ?? false;
    }

    /**
     * Asked without a specific grant, because the page renders the button before
     * it knows which link will be revoked. The per-grant check still runs in
     * ClientAccessGrantPolicy::revoke when the request actually arrives — this
     * only decides whether to draw the control.
     */
    public function canRevoke(?User $user): bool
    {
        if (! $user instanceof User) {
            return false;
        }

        return $user->can('create', ClientAccessGrant::class);
    }
}
