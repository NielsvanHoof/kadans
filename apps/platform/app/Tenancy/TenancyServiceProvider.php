<?php

declare(strict_types=1);

namespace App\Tenancy;

use App\Accounts\Models\User;
use App\Tenancy\Contracts\SupportSessionState;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Models\TenantInvitation;
use App\Tenancy\Models\TenantMembership;
use App\Tenancy\Models\TenantRole;
use App\Tenancy\Models\TenantSetting;
use App\Tenancy\Policies\TenantInvitationPolicy;
use App\Tenancy\Policies\TenantMembershipPolicy;
use App\Tenancy\Policies\TenantRolePolicy;
use App\Tenancy\Policies\TenantSettingsPolicy;
use App\Tenancy\Support\Roles\PermissionRegistry;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Override;

/**
 * Tenancy container bindings, policies, and permission Gate overlay.
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
                Config::array('tenant-permissions.enums'),
            ),
        );
    }

    public function boot(): void
    {
        Gate::policy(TenantInvitation::class, TenantInvitationPolicy::class);
        Gate::policy(TenantMembership::class, TenantMembershipPolicy::class);
        Gate::policy(TenantRole::class, TenantRolePolicy::class);
        Gate::policy(TenantSetting::class, TenantSettingsPolicy::class);

        $this->configurePermissionGate();
    }

    /**
     * Resolve Spatie permission strings on the Gate, including the support overlay.
     *
     * Spatie's own Gate::before (`register_permission_check_method`) looks up
     * every ability as a permission name. Policy methods such as viewAny then
     * throw PermissionDoesNotExist, which translates vendor exception copy on
     * every authorize() call. We skip unregistered abilities so policies run.
     */
    private function configurePermissionGate(): void
    {
        Gate::before(function (mixed $user, string $ability): ?bool {
            if (! $user instanceof User) {
                return null;
            }

            if (! resolve(PermissionRegistry::class)->isRegistered($ability)) {
                return null;
            }

            $tenant = Tenant::current();
            $supportSession = resolve(SupportSessionState::class);

            // Support session takes priority over firm membership (read-only overlay).
            if ($user->isPlatformSupport() && $tenant instanceof Tenant && $supportSession->isActiveFor($tenant)) {
                return $supportSession->allowsPermission($ability);
            }

            // Return null (not false) on deny so later Gate checks and policies still run.
            return $user->checkPermissionTo($ability) ? true : null;
        });
    }
}
