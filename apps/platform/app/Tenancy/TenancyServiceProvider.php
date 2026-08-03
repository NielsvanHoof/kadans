<?php

declare(strict_types=1);

namespace App\Tenancy;

use App\Accounts\Models\User;
use App\Tenancy\Contracts\SupportSessionState;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Models\TenantInvitation;
use App\Tenancy\Models\TenantMembership;
use App\Tenancy\Models\TenantRole;
use App\Tenancy\Policies\TenantInvitationPolicy;
use App\Tenancy\Policies\TenantMembershipPolicy;
use App\Tenancy\Policies\TenantRolePolicy;
use App\Tenancy\Support\PermissionRegistry;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Override;

/**
 * Tenancy container bindings, policies, and support Gate overlay.
 */
final class TenancyServiceProvider extends ServiceProvider
{
    #[Override]
    public function register(): void
    {
        // Derived from config only — safe as a singleton across Octane requests.
        $this->app->singleton(
            PermissionRegistry::class,
            static fn (): PermissionRegistry => new PermissionRegistry(
                config()->array('tenant-permissions.enums'),
            ),
        );
    }

    public function boot(): void
    {
        Gate::policy(TenantInvitation::class, TenantInvitationPolicy::class);
        Gate::policy(TenantMembership::class, TenantMembershipPolicy::class);
        Gate::policy(TenantRole::class, TenantRolePolicy::class);

        $this->configureSupportPermissionGate();
    }

    /**
     * Grant read-only abilities to platform support users in an active firm session.
     * Hard-denies mutate abilities while a support session is active.
     * Only intercepts Spatie permission strings — policy methods (viewAny, etc.) continue normally.
     */
    private function configureSupportPermissionGate(): void
    {
        Gate::before(function (mixed $user, string $ability): ?bool {
            if (! $user instanceof User || ! $user->isPlatformSupport()) {
                return null;
            }

            // Policy abilities like "viewAny" must not be short-circuited here.
            if (! resolve(PermissionRegistry::class)->isRegistered($ability)) {
                return null;
            }

            $tenant = Tenant::current();

            if (! $tenant instanceof Tenant) {
                return null;
            }

            $supportSession = resolve(SupportSessionState::class);

            // Support session takes priority over firm membership (read-only overlay).
            if (! $supportSession->isActiveFor($tenant)) {
                return null;
            }

            return $supportSession->allowsPermission($ability);
        });
    }
}
