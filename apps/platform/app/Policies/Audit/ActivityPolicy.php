<?php

declare(strict_types=1);

namespace App\Policies\Audit;

use App\Enums\Permission;
use App\Models\User;
use App\Policies\Concerns\AuthorizesTenantPermission;

final class ActivityPolicy
{
    use AuthorizesTenantPermission;

    /**
     * Whether the user may browse the tenant activity / audit log.
     */
    public function viewAny(User $user): bool
    {
        return $this->userHasPermissionInCurrentTenant($user, Permission::ViewAuditLog);
    }
}
