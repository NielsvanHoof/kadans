<?php

declare(strict_types=1);

namespace App\Audit\Policies;

use App\Accounts\Models\User;
use App\Audit\Enums\AuditPermission;
use App\Tenancy\Policies\Concerns\AuthorizesTenantPermission;

final class ActivityPolicy
{
    use AuthorizesTenantPermission;

    /**
     * Whether the user may browse the tenant activity / audit log.
     */
    public function viewAny(User $user): bool
    {
        return $this->userHasPermissionInCurrentTenant($user, AuditPermission::View);
    }
}
