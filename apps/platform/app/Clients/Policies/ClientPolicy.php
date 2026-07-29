<?php

declare(strict_types=1);

namespace App\Clients\Policies;

use App\Accounts\Models\User;
use App\Clients\Enums\ClientPermission;
use App\Clients\Models\Client;
use App\Tenancy\Policies\Concerns\AuthorizesTenantPermission;

final class ClientPolicy
{
    use AuthorizesTenantPermission;

    public function viewAny(User $user): bool
    {
        return $this->userHasPermissionInCurrentTenant($user, ClientPermission::View);
    }

    public function view(User $user, Client $client): bool
    {
        return $this->userHasPermissionInTenant(
            $user,
            $client->tenant,
            ClientPermission::View,
        );
    }

    public function create(User $user): bool
    {
        return $this->userHasPermissionInCurrentTenant($user, ClientPermission::Create);
    }

    public function update(User $user, Client $client): bool
    {
        return $this->userHasPermissionInTenant(
            $user,
            $client->tenant,
            ClientPermission::Update,
        );
    }

    public function delete(User $user, Client $client): bool
    {
        return $this->userHasPermissionInTenant(
            $user,
            $client->tenant,
            ClientPermission::Delete,
        );
    }

    /**
     * Class-level gate for bulk archiving, where there is no single client yet.
     */
    public function deleteAny(User $user): bool
    {
        return $this->userHasPermissionInCurrentTenant($user, ClientPermission::Delete);
    }

    public function restore(User $user, Client $client): bool
    {
        return $this->userHasPermissionInTenant(
            $user,
            $client->tenant,
            ClientPermission::Restore,
        );
    }

    /**
     * Class-level gate for the recycle bin and bulk restore.
     */
    public function restoreAny(User $user): bool
    {
        return $this->userHasPermissionInCurrentTenant($user, ClientPermission::Restore);
    }
}
