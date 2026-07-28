<?php

declare(strict_types=1);

namespace App\Tenancy\Policies;

use App\Accounts\Models\User;
use App\Tenancy\Enums\Permission;
use App\Tenancy\Enums\Role;
use App\Tenancy\Enums\TenantMembershipStatus;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Models\TenantMembership;
use App\Tenancy\Policies\Concerns\AuthorizesTenantPermission;
use App\Tenancy\Support\WorkspaceMemberRoles;

final class TenantMembershipPolicy
{
    use AuthorizesTenantPermission;

    public function __construct(private readonly WorkspaceMemberRoles $memberRoles) {}

    public function viewAny(User $user): bool
    {
        return $this->userHasPermissionInCurrentTenant($user, Permission::ManageMembers);
    }

    public function update(User $user, TenantMembership $membership): bool
    {
        $membership->loadMissing('tenant');
        $tenant = $membership->tenant;

        if (! $tenant instanceof Tenant) {
            return false;
        }

        return $this->userHasPermissionInTenant(
            $user,
            $tenant,
            Permission::ManageMembers,
        );
    }

    public function suspend(User $user, TenantMembership $membership): bool
    {
        if (! $this->update($user, $membership)) {
            return false;
        }

        if ($membership->user_id === $user->id) {
            return false;
        }

        return $membership->status === TenantMembershipStatus::Active;
    }

    public function remove(User $user, TenantMembership $membership): bool
    {
        if (! $this->update($user, $membership)) {
            return false;
        }

        $membership->loadMissing(['tenant', 'user']);
        $tenant = $membership->tenant;

        if (! $tenant instanceof Tenant) {
            return false;
        }

        $member = $membership->user;

        if (! $member instanceof User) {
            return false;
        }

        // Self-removal is allowed only when another owner remains.
        if ($membership->user_id === $user->id) {
            return ! $this->memberRoles->isLastActiveOwner($member, $tenant);
        }

        return true;
    }

    public function assignRole(User $user, TenantMembership $membership, Role $role): bool
    {
        if (! $this->update($user, $membership)) {
            return false;
        }

        $membership->loadMissing('tenant');
        $tenant = $membership->tenant;

        if (! $tenant instanceof Tenant) {
            return false;
        }

        return $this->memberRoles->actorCanAssignRole($user, $tenant, $role);
    }
}
