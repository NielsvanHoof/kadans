<?php

declare(strict_types=1);

namespace App\Tenancy\Policies;

use App\Accounts\Models\User;
use App\Tenancy\Contracts\TenantPermission;
use App\Tenancy\Enums\Role;
use App\Tenancy\Enums\TenantMembershipStatus;
use App\Tenancy\Enums\WorkspacePermission;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Models\TenantMembership;
use App\Tenancy\Policies\Concerns\AuthorizesTenantPermission;
use App\Tenancy\Support\Members\WorkspaceMemberRoles;

final readonly class TenantMembershipPolicy
{
    use AuthorizesTenantPermission;

    public function __construct(private WorkspaceMemberRoles $memberRoles) {}

    public function viewAny(User $user): bool
    {
        return $this->userHasPermissionInCurrentTenant($user, WorkspacePermission::ViewMembers);
    }

    /**
     * Gate for the member role update endpoint.
     */
    public function update(User $user, TenantMembership $membership): bool
    {
        // Mirror suspend: never let a member change their own role assignment.
        if ($membership->user_id === $user->id) {
            return false;
        }

        return $this->holdsPermissionInMembershipTenant(
            $user,
            $membership,
            WorkspacePermission::AssignMemberRoles,
        );
    }

    public function suspend(User $user, TenantMembership $membership): bool
    {
        if (! $this->holdsPermissionInMembershipTenant($user, $membership, WorkspacePermission::SuspendMembers)) {
            return false;
        }

        if ($membership->user_id === $user->id) {
            return false;
        }

        return $membership->status === TenantMembershipStatus::Active;
    }

    public function remove(User $user, TenantMembership $membership): bool
    {
        if (! $this->holdsPermissionInMembershipTenant($user, $membership, WorkspacePermission::RemoveMembers)) {
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

    public function assignRole(User $user, TenantMembership $membership, Role|string $role): bool
    {
        if ($membership->user_id === $user->id) {
            return false;
        }

        if (! $this->holdsPermissionInMembershipTenant($user, $membership, WorkspacePermission::AssignMemberRoles)) {
            return false;
        }

        $membership->loadMissing('tenant');
        $tenant = $membership->tenant;

        if (! $tenant instanceof Tenant) {
            return false;
        }

        $roleName = $role instanceof Role ? $role->value : $role;

        return $this->memberRoles->actorCanAssignRole($user, $tenant, $roleName);
    }

    private function holdsPermissionInMembershipTenant(
        User $user,
        TenantMembership $membership,
        TenantPermission $permission,
    ): bool {
        $membership->loadMissing('tenant');
        $tenant = $membership->tenant;

        if (! $tenant instanceof Tenant) {
            return false;
        }

        return $this->userHasPermissionInTenant($user, $tenant, $permission);
    }
}
