<?php

declare(strict_types=1);

namespace App\ClientPortal\Policies;

use App\Accounts\Models\User;
use App\ClientPortal\Enums\ClientAccessGrantPermission;
use App\ClientPortal\Models\ClientAccessGrant;
use App\Tenancy\Policies\Concerns\AuthorizesTenantPermission;

final class ClientAccessGrantPolicy
{
    use AuthorizesTenantPermission;

    public function create(User $user): bool
    {
        return $this->userHasPermissionInCurrentTenant($user, ClientAccessGrantPermission::Grant);
    }

    public function revoke(User $user, ClientAccessGrant $grant): bool
    {
        return $this->userHasPermissionInTenant(
            $user,
            $grant->tenant,
            ClientAccessGrantPermission::Revoke,
        );
    }
}
