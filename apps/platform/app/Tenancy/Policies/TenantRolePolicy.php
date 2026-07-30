<?php

declare(strict_types=1);

namespace App\Tenancy\Policies;

use App\Accounts\Models\User;
use App\Tenancy\Enums\WorkspacePermission;
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
            && $this->userHasPermissionInCurrentTenant($user, WorkspacePermission::ManageRoles);
    }

    public function delete(User $user, TenantRole $role): bool
    {
        return ! $role->is_system
            && $this->userHasPermissionInCurrentTenant($user, WorkspacePermission::ManageRoles);
    }
}
