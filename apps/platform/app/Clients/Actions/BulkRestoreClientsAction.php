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
     * @param  Collection<int, Client>  $clients
     */
    public function handle(Collection $clients, User $restoredBy): int
    {
        $count = 0;

        foreach ($clients as $client) {
            Gate::forUser($restoredBy)->authorize('restore', $client);
            $this->restoreClient->handle($client, $restoredBy);
            $count++;
        }

        return $count;
    }
}
