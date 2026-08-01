<?php

declare(strict_types=1);

namespace App\Clients\Queries;

use App\Clients\Data\ClientDossierRowData;
use App\Clients\Data\ClientShowPageData;
use App\Clients\Data\ClientSummaryData;
use App\Clients\Models\Client;
use App\Dossiers\Models\Dossier;

final class FetchClientShowQuery
{
    public function handle(Client $client): ClientShowPageData
    {
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
        );
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
