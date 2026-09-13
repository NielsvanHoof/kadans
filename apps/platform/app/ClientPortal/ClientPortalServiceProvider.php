<?php

declare(strict_types=1);

namespace App\ClientPortal;

use App\ClientPortal\Models\ClientAccessGrant;
use App\ClientPortal\Policies\ClientAccessGrantPolicy;
use App\ClientPortal\Support\Outreach\ClientPortalReminderDispatcher;
use App\Dossiers\Contracts\Reminders\ClientPortalReminders;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Override;

/**
 * Client portal domain authorization and Dossiers-facing bindings.
 */
final class ClientPortalServiceProvider extends ServiceProvider
{
    #[Override]
    public function register(): void
    {
        $this->app->bind(ClientPortalReminders::class, ClientPortalReminderDispatcher::class);
    }

    public function boot(): void
    {
        Gate::policy(ClientAccessGrant::class, ClientAccessGrantPolicy::class);
    }
}
