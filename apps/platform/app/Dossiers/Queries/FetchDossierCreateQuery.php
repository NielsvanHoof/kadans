<?php

declare(strict_types=1);

namespace App\Dossiers\Queries;

use App\Clients\Models\Client;
use App\Shared\Data\PersonOptionData;

final class FetchDossierCreateQuery
{
    /**
     * @return list<PersonOptionData>
     */
    public function handle(): array
    {
        /** @var list<PersonOptionData> $clients */
        $clients = [];

        foreach (
            Client::query()
                ->get(['id', 'name', 'email'])
                ->sortBy('name')
                ->values() as $client
        ) {
            $clients[] = new PersonOptionData(
                id: $client->id,
                name: $client->name,
                email: $client->email,
            );
        }

        return $clients;
    }
}
