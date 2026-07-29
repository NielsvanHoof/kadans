<?php

declare(strict_types=1);

namespace App\Tenancy\Policies;

use App\Accounts\Models\User;
use App\Tenancy\Enums\WorkspacePermission;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Models\TenantInvitation;
use App\Tenancy\Policies\Concerns\AuthorizesTenantPermission;

final class TenantInvitationPolicy
{
    use AuthorizesTenantPermission;

    public function create(User $user): bool
    {
        return $this->userHasPermissionInCurrentTenant($user, WorkspacePermission::InviteMembers);
    }

    public function resend(User $user, TenantInvitation $invitation): bool
    {
        return $this->manage($user, $invitation) && $invitation->isPending();
    }

    public function revoke(User $user, TenantInvitation $invitation): bool
    {
        return $this->manage($user, $invitation) && ! $invitation->isAccepted();
    }

    private function manage(User $user, TenantInvitation $invitation): bool
    {
        $invitation->loadMissing('tenant');
        $tenant = $invitation->tenant;

        if (! $tenant instanceof Tenant) {
            return false;
        }

        return $this->userHasPermissionInTenant(
            $user,
            $tenant,
            WorkspacePermission::InviteMembers,
        );
    }
}
