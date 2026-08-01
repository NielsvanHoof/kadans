<?php

declare(strict_types=1);

namespace App\Clients\Actions;

use App\Accounts\Models\User;
use App\Clients\Models\Client;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

final readonly class BulkRestoreClientsAction
{
    public function __construct(private RestoreClientAction $restoreClient) {}

    /**
     * Restore each selected archived client (dossiers stay archived).
     *
     * @param  list<int>  $clientIds
     */
    public function handle(array $clientIds, User $restoredBy): int
    {
        $count = 0;

        foreach ($this->resolveTrashedClients($clientIds) as $client) {
            Gate::forUser($restoredBy)->authorize('restore', $client);
            $this->restoreClient->handle($client, $restoredBy);
            $count++;
        }

        return $count;
    }

    /**
     * Tenant scoping still applies; getQuery() only sidesteps the scoped
     * builder's whereIn() signature.
     *
     * @param  list<int>  $clientIds
     * @return Collection<int, Client>
     */
    private function resolveTrashedClients(array $clientIds): Collection
    {
        $clientsQuery = Client::onlyTrashed()->with('tenant');
        $clientsQuery->getQuery()->whereIn('id', $clientIds);

        return $clientsQuery->get();
    }
}
