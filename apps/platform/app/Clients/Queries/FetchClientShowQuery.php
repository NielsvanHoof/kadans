<?php

declare(strict_types=1);

namespace App\Clients\Queries;

use App\Clients\Contracts\ClientPortalAccess;
use App\Clients\Data\ClientDossierRowData;
use App\Clients\Data\ClientShowPageData;
use App\Clients\Data\ClientSummaryData;
use App\Clients\Models\Client;
use App\Dossiers\Models\Dossier;
use Illuminate\Http\Request;

use function is_string;

final readonly class FetchClientShowQuery
{
    /**
     * Portal links arrive through a contract, not a ClientPortal import: this
     * is L4 and that is L5. Clients owns the question and the answer's shape;
     * ClientPortal binds the implementation.
     */
    public function __construct(private ClientPortalAccess $clientPortalAccess) {}

    public function handle(Request $request, Client $client): ClientShowPageData
    {
        $user = $request->user();

        $activeDossiersCount = $client->dossiers()->toBase()->count();
        $archivedDossiersQuery = Dossier::onlyTrashed();
        $archivedDossiersQuery->getQuery()->where('client_id', $client->id);
        $archivedDossiersCount = $archivedDossiersQuery->toBase()->count();

        $dossiers = Dossier::query()
            ->whereBelongsTo($client)
            ->latest('updated_at')
            ->paginate(perPage: 15, pageName: 'dossiers_page')
            ->withQueryString()
            ->through(fn (Dossier $dossier): array => $this->mapDossierRow($dossier, $client));

        return new ClientShowPageData(
            client: new ClientSummaryData(
                id: $client->id,
                name: $client->name,
                email: $client->email,
                activeDossiersCount: $activeDossiersCount,
                archivedDossiersCount: $archivedDossiersCount,
            ),
            dossiers: $dossiers,
            portalLinks: $this->clientPortalAccess->linksFor($client),
            canGrantPortalAccess: $this->clientPortalAccess->canIssue($user),
            canRevokePortalAccess: $this->clientPortalAccess->canRevoke($user),
            accessGrantPortalUrl: $this->flashedPortalUrl($request),
        );
    }

    /**
     * Pulled, not read: the plaintext link is shown exactly once, and leaving it
     * in the session would put it back on the page after a refresh.
     */
    private function flashedPortalUrl(Request $request): ?string
    {
        $url = $request->session()->pull('access_grant_portal_url');

        return is_string($url) && $url !== '' ? $url : null;
    }

    /**
     * Row shape is owned by ClientDossierRowData; see its generated TypeScript.
     *
     * @return array<string, mixed>
     */
    private function mapDossierRow(Dossier $dossier, Client $client): array
    {
        return new ClientDossierRowData(
            id: $dossier->id,
            title: $dossier->title,
            reference: $dossier->reference,
            status: $dossier->status,
            clientName: $client->name,
            updatedAt: $dossier->updated_at?->toIso8601String() ?? '',
        )->toArray();
    }
}
