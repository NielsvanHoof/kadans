<?php

declare(strict_types=1);

namespace App\Clients\Policies;

use App\Accounts\Models\User;
use App\Clients\Models\Client;
use App\Tenancy\Enums\Permission;
use App\Tenancy\Policies\Concerns\AuthorizesTenantPermission;

final class ClientPolicy
{
    use AuthorizesTenantPermission;

    public function viewAny(User $user): bool
    {
        return $this->userHasPermissionInCurrentTenant($user, Permission::ManageClients);
    }

    public function view(User $user, Client $client): bool
    {
        return $this->userHasPermissionInTenant(
            $user,
            $client->tenant,
            Permission::ManageClients,
        );
    }

    public function create(User $user): bool
    {
        return $this->userHasPermissionInCurrentTenant($user, Permission::ManageClients);
    }

    public function update(User $user, Client $client): bool
    {
        return $this->userHasPermissionInTenant(
            $user,
            $client->tenant,
            Permission::ManageClients,
        );
    }

    public function delete(User $user, Client $client): bool
    {
        return $this->userHasPermissionInTenant(
            $user,
            $client->tenant,
            Permission::ManageClients,
        );
    }

    public function restore(User $user, Client $client): bool
    {
        return $this->userHasPermissionInTenant(
            $user,
            $client->tenant,
            Permission::ManageClients,
        );
    }
}
