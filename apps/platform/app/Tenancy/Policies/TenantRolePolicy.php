<?php

declare(strict_types=1);

namespace App\Tenancy\Policies;

use App\Accounts\Models\User;
use App\Tenancy\Enums\WorkspacePermission;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Models\TenantRole;
use App\Tenancy\Policies\Concerns\AuthorizesTenantPermission;

final class TenantRolePolicy
{
    use AuthorizesTenantPermission;

    public function viewAny(User $user): bool
    {
        return $this->userHasPermissionInCurrentTenant($user, WorkspacePermission::ViewRoles);
    }

    public function create(User $user): bool
    {
        return $this->userHasPermissionInCurrentTenant($user, WorkspacePermission::ManageRoles);
    }

    public function update(User $user, TenantRole $role): bool
    {
        return ! $role->is_system
            && $this->userCanManageRoleInItsTenant($user, $role);
    }

    public function delete(User $user, TenantRole $role): bool
    {
        return ! $role->is_system
            && $this->userCanManageRoleInItsTenant($user, $role);
    }

    /**
     * ManageRoles must hold on the role's own firm — not merely the current session.
     */
    private function userCanManageRoleInItsTenant(User $user, TenantRole $role): bool
    {
        $tenant = Tenant::query()->find($role->tenant_id);

        return $this->userHasPermissionInTenant(
            $user,
            $tenant,
            WorkspacePermission::ManageRoles,
        );
    }
}
