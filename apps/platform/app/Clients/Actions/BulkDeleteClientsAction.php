<?php

declare(strict_types=1);

namespace App\Clients\Actions;

use App\Accounts\Models\User;
use App\Clients\Models\Client;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

final readonly class BulkDeleteClientsAction
{
    public function __construct(private DeleteClientAction $deleteClient) {}

    /**
     * Archive each selected client (cascades live dossiers via DeleteClientAction).
     *
     * @param  list<int>  $clientIds
     */
    public function handle(array $clientIds, User $deletedBy): int
    {
        $count = 0;

        foreach ($this->resolveClients($clientIds) as $client) {
            Gate::forUser($deletedBy)->authorize('delete', $client);
            $this->deleteClient->handle($client, $deletedBy);
            $count++;
        }

        return $count;
    }

    /**
     * Tenant scoping still applies; getQuery() only sidesteps the scoped
     * builder's whereIn() signature, and is kept here rather than in the
     * controller so the escape hatch lives next to the domain logic.
     *
     * @param  list<int>  $clientIds
     * @return Collection<int, Client>
     */
    private function resolveClients(array $clientIds): Collection
    {
        $clientsQuery = Client::query()->with('tenant');
        $clientsQuery->getQuery()->whereIn('id', $clientIds);

        return $clientsQuery->get();
    }
}
