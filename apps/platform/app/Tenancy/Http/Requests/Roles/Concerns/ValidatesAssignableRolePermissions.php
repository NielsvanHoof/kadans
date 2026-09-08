<?php

declare(strict_types=1);

namespace App\Tenancy\Http\Requests\Roles\Concerns;

use App\Accounts\Models\User;
use App\Tenancy\Contracts\TenantPermission;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Support\Roles\PermissionRegistry;

/**
 * Permissions allowed on firm-authored roles: not owner-only, and held by the actor.
 */
trait ValidatesAssignableRolePermissions
{
    /**
     * @return list<string>
     */
    protected function assignablePermissionValues(): array
    {
        $user = $this->user();
        $tenant = $this->route('tenant');
        $registry = resolve(PermissionRegistry::class);

        if (! $user instanceof User || ! $tenant instanceof Tenant) {
            return [];
        }

        $allowed = [];

        foreach ($registry->firmAssignableValues() as $value) {
            $permission = $registry->tryFrom($value);

            if ($permission instanceof TenantPermission
                && $user->hasTenantPermission($permission, $tenant)
            ) {
                $allowed[] = $value;
            }
        }

        return $allowed;
    }
}
