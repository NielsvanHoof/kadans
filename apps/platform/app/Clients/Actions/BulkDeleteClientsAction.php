<?php

declare(strict_types=1);

namespace App\Clients\Actions;

use App\Accounts\Models\User;
use App\Clients\Models\Client;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

final class BulkDeleteClientsAction
{
    public function __construct(private readonly DeleteClientAction $deleteClient) {}

    /**
     * Archive each selected client (cascades live dossiers via DeleteClientAction).
     *
     * @param  Collection<int, Client>  $clients
     */
    public function handle(Collection $clients, User $deletedBy): int
    {
        $count = 0;

        foreach ($clients as $client) {
            Gate::forUser($deletedBy)->authorize('delete', $client);
            $this->deleteClient->handle($client, $deletedBy);
            $count++;
        }

        return $count;
    }
}
