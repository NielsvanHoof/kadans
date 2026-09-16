<?php

declare(strict_types=1);

namespace App\ClientPortal\Queries\Access;

use App\ClientPortal\Enums\ClientAccessGrantScope;
use App\ClientPortal\Models\ClientAccessGrant;
use App\Dossiers\Enums\DossierStatus;
use App\Dossiers\Models\Dossier;
use Illuminate\Database\Eloquent\Collection;

/**
 * Which dossiers a magic link opens.
 *
 * The single definition of portal reachability. Everything else in the portal —
 * the page, the answer and upload endpoints, the upload-ticket signer — asks
 * ResolveGrantedDossierQuery, which is built on this, so there is one predicate
 * rather than a comparison repeated at each adapter.
 *
 * Three filters, and all three matter:
 *
 * - Scope. A dossier-scoped grant opens the one dossier it names; a
 *   client-scoped grant opens the client's shared set, re-evaluated per request
 *   so a dossier shared next month appears behind a link sent today.
 * - Status. Draft is unshared and unreachable (DossierStatus::isSharedWithClient).
 *   Applied to both scopes: a dossier-scoped grant's dossier is shared as the
 *   grant is minted and can never return to Draft, so this only ever narrows the
 *   client-scoped case in practice, and one rule beats two.
 * - Tenant and soft deletes, which arrive for free from TenantScope (strict on
 *   portal requests) and SoftDeletes.
 *
 * A Dossier-scoped grant whose dossier_id is somehow null matches nothing rather
 * than falling through to the client's whole set. That is the safe direction.
 */
final readonly class FetchReachableDossiersQuery
{
    /**
     * @param  ?int  $onlyDossierId  Narrow to one candidate, for a request
     *                               that already names the dossier it means.
     * @param  bool  $withDocumentRequests  Eager-load items, for callers that go on
     *                                      to count what is outstanding.
     * @return Collection<int, Dossier>
     */
    public function handle(
        ClientAccessGrant $grant,
        ?int $onlyDossierId = null,
        bool $withDocumentRequests = false,
    ): Collection {
        $query = Dossier::query();

        // Constraints go on the base query: phpstan-strict-rules flags
        // Builder::whereIn()/where() as staticMethod.dynamicCall otherwise, the
        // same way FetchDossierShowQuery handles its grant lookups.
        $base = $query->getQuery();
        $base->whereIn('status', DossierStatus::sharedWithClientValues());

        match ($grant->scope) {
            ClientAccessGrantScope::Dossier => $base->where('id', $grant->dossier_id),
            ClientAccessGrantScope::Client => $base->where('client_id', $grant->client_id),
        };

        if ($onlyDossierId !== null) {
            $base->where('id', $onlyDossierId);
        }

        if ($withDocumentRequests) {
            $query->with([
                'documentRequests' => function ($itemQuery): void {
                    $itemQuery->getQuery()->orderBy('sort_order')->orderBy('id');
                },
            ]);
        }

        $base->oldest('due_date')->orderBy('id');

        return $query->get();
    }

    /**
     * Whether this grant opens that dossier.
     *
     * Used by the submission actions, which re-check under the grant row lock
     * and need a boolean they can turn into their own exception rather than the
     * 404 ResolveGrantedDossierQuery raises.
     */
    public function covers(ClientAccessGrant $grant, ?int $dossierId): bool
    {
        if ($dossierId === null) {
            return false;
        }

        return $this->handle($grant, onlyDossierId: $dossierId)->isNotEmpty();
    }
}
