<?php

declare(strict_types=1);

namespace App\Platform\Http\SharedProps;

use App\Accounts\Models\User;
use App\Tenancy\Contracts\SupportSessionState;
use App\Tenancy\Contracts\TenantPermission;
use App\Tenancy\Enums\Role;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Support\PermissionRegistry;
use App\Tenancy\Support\WorkspaceMemberRoles;

/**
 * Every registered permission, resolved for the current user in the current firm.
 *
 * Replaces the hand-written can_manage_members / can_view_roles /
 * can_view_pipelines props, which had to grow by one entry in this middleware
 * every time the UI wanted to gate on another permission. Driving it from
 * PermissionRegistry means adding a nav item is now a frontend-only change.
 *
 * The fan-out is over the whole registry, so the loop body has to stay genuinely
 * cheap. User::hasTenantPermission() is the right entry point for a single
 * check but the wrong one to call 48 times: it re-resolves SupportSessionState
 * from the container and re-walks the membership list on every call. Neither
 * answer can change between iterations, so both are settled once below and the
 * loop keeps only the part that actually varies per permission.
 */
final readonly class TenantPermissionProps
{
    public function __construct(private PermissionRegistry $permissionRegistry) {}

    /**
     * @return array<string, bool>
     */
    public function __invoke(?User $user): array
    {
        $tenant = Tenant::current();

        if (! $user instanceof User || ! $tenant instanceof Tenant) {
            return [];
        }
        $supportSession = resolve(SupportSessionState::class);
        $onSupportSession = $user->isPlatformSupport() && $supportSession->isActiveFor($tenant);
        $isMember = $user->belongsToTenant($tenant);

        if (! $onSupportSession && ! $isMember) {
            return [];
        }

        $permissions = [];

        if ($onSupportSession) {
            foreach ($this->permissionRegistry->all() as $permission) {
                $permissions[$permission->value] = $supportSession->allowsPermission($permission);
            }

            return $permissions;
        }

        $memberRoles = resolve(WorkspaceMemberRoles::class);
        $roleName = $memberRoles->roleFor($user, $tenant);

        if ($roleName === Role::Owner->value) {
            foreach ($this->permissionRegistry->all() as $permission) {
                $permissions[$permission->value] = true;
            }

            return $permissions;
        }

        $granted = array_flip($user->getAllPermissions()->pluck('name')->all());

        foreach ($this->permissionRegistry->all() as $permission) {
            $permissions[$permission->value] = isset($granted[$permission->value]);
        }

        return $permissions;
    }

    /**
     * Permission values in registration order, so the frontend type can be a
     * closed union rather than an open Record.
     *
     * @return list<string>
     */
    public function values(): array
    {
        return array_map(
            static fn (TenantPermission $permission): string => $permission->value,
            $this->permissionRegistry->all(),
        );
    }
}
