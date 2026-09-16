<?php

declare(strict_types=1);

namespace App\ClientPortal;

use App\ClientPortal\Models\ClientAccessGrant;
use App\ClientPortal\Policies\ClientAccessGrantPolicy;
use App\ClientPortal\Support\Access\ClientPortalAccessProvider;
use App\ClientPortal\Support\Outreach\ClientPortalReminderDispatcher;
use App\Clients\Contracts\ClientPortalAccess;
use App\Dossiers\Contracts\Reminders\ClientPortalReminders;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Override;

/**
 * Client portal domain authorization, plus the bindings that let Dossiers and
 * Clients ask about portal access without importing this layer.
 */
final class ClientPortalServiceProvider extends ServiceProvider
{
    #[Override]
    public function register(): void
    {
        $this->app->bind(ClientPortalReminders::class, ClientPortalReminderDispatcher::class);
        $this->app->bind(ClientPortalAccess::class, ClientPortalAccessProvider::class);
    }

    public function boot(): void
    {
        Gate::policy(ClientAccessGrant::class, ClientAccessGrantPolicy::class);
    }
}
