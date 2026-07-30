<?php

declare(strict_types=1);

namespace App\Tenancy\Policies;

use App\Accounts\Models\User;
use App\Tenancy\Enums\WorkspacePermission;
use App\Tenancy\Policies\Concerns\AuthorizesTenantPermission;

final class TenantRolePolicy
{
    use AuthorizesTenantPermission;

    public function viewAny(User $user): bool
    {
        return $this->userHasPermissionInCurrentTenant($user, WorkspacePermission::ViewRoles);
    }
}
