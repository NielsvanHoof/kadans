<?php

declare(strict_types=1);

namespace App\ClientPortal;

use App\ClientPortal\Models\ClientAccessGrant;
use App\ClientPortal\Policies\ClientAccessGrantPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Client portal domain authorization.
 */
final class ClientPortalServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Gate::policy(ClientAccessGrant::class, ClientAccessGrantPolicy::class);
    }
}
