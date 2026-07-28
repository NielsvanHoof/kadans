<?php

declare(strict_types=1);

namespace App\Tenancy\Policies\Concerns;

use App\Accounts\Models\User;
use App\Tenancy\Enums\Permission;
use App\Tenancy\Models\Tenant;

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
