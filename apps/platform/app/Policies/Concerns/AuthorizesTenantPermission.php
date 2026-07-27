<?php

declare(strict_types=1);

namespace App\Policies\Concerns;

use App\Enums\Permission;
use App\Models\Tenant;
use App\Models\User;

trait AuthorizesTenantPermission
{
    /**
     * Whether the user holds a permission in the given tenant.
     * Delegates to User::hasTenantPermission() (support session + Spatie roles).
     */
    protected function userHasPermissionInTenant(
        User $user,
        ?Tenant $tenant,
        Permission $permission,
    ): bool {
        return $user->hasTenantPermission($permission, $tenant);
    }

    /**
     * Permission check against the current tenant (for viewAny / create gates).
     */
    protected function userHasPermissionInCurrentTenant(User $user, Permission $permission): bool
    {
        return $user->hasTenantPermission($permission, Tenant::current());
    }
}
