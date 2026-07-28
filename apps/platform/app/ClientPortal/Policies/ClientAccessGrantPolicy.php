<?php

declare(strict_types=1);

namespace App\ClientPortal\Policies;

use App\Accounts\Models\User;
use App\ClientPortal\Models\ClientAccessGrant;
use App\Tenancy\Enums\Permission;
use App\Tenancy\Policies\Concerns\AuthorizesTenantPermission;

final class ClientAccessGrantPolicy
{
    use AuthorizesTenantPermission;

    public function create(User $user): bool
    {
        return $this->userHasPermissionInCurrentTenant($user, Permission::CreateDossiers);
    }

    public function revoke(User $user, ClientAccessGrant $grant): bool
    {
        return $this->userHasPermissionInTenant(
            $user,
            $grant->tenant,
            Permission::CreateDossiers,
        );
    }
}
