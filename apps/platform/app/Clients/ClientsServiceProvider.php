<?php

declare(strict_types=1);

namespace App\Clients;

use App\Clients\Models\Client;
use App\Clients\Policies\ClientPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Clients domain authorization.
 */
final class ClientsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Gate::policy(Client::class, ClientPolicy::class);
    }
}
