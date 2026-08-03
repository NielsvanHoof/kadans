<?php

declare(strict_types=1);

namespace App\Platform\Http\SharedProps;

use App\Accounts\Models\User;
use App\Tenancy\Contracts\TenantPermission;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Support\PermissionRegistry;

/**
 * Every registered permission, resolved for the current user in the current firm.
 *
 * Replaces the hand-written can_manage_members / can_view_roles /
 * can_view_pipelines props, which had to grow by one entry in this middleware
 * every time the UI wanted to gate on another permission. Driving it from
 * PermissionRegistry means adding a nav item is now a frontend-only change.
 *
 * Cheap despite the fan-out: Spatie loads the user's permissions once per
 * request, so the remaining checks are in-memory.
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

        $permissions = [];

        foreach ($this->permissionRegistry->all() as $permission) {
            $permissions[$permission->value] = $user->hasTenantPermission($permission, $tenant);
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
