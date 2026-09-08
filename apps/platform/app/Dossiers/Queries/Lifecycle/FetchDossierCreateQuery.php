<?php

declare(strict_types=1);

namespace App\Dossiers\Queries;

use App\Clients\Models\Client;
use App\Shared\Data\PersonOptionData;

/**
 * Client options for the dossier-create picker.
 *
 * This used to load every client row and sort them in PHP with
 * Collection::sortBy — no ORDER BY, no LIMIT — to fill a plain select. The
 * database sorts, and the list is bounded: a firm past the cap creates the
 * dossier from the client's own page instead, where the client is already
 * identified.
 */
final class FetchDossierCreateQuery
{
    /**
     * Sized well above any realistic firm's book so the picker stays a plain
     * select, while still bounding the query.
     */
    private const int CLIENT_PICKER_LIMIT = 500;

    /**
     * @return list<PersonOptionData>
     */
    public function handle(): array
    {
        /** @var list<PersonOptionData> $clients */
        $clients = [];

        // orderBy/limit go through getQuery(): phpstan-strict-rules reads a
        // chained call on the Eloquent builder as a dynamic static call.
        $clientQuery = Client::select(['id', 'name', 'email']);
        $clientQuery->getQuery()
            ->orderBy('name')
            ->limit(self::CLIENT_PICKER_LIMIT);

        $rows = $clientQuery->get();

        foreach ($rows as $client) {
            $clients[] = new PersonOptionData(
                id: $client->id,
                name: $client->name,
                email: $client->email,
            );
        }

        return $clients;
    }
}
