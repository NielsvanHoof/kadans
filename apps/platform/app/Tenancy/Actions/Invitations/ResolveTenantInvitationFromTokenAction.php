<?php

declare(strict_types=1);

namespace App\Tenancy\Actions\Invitations;

use App\Tenancy\Models\TenantInvitation;

final class ResolveTenantInvitationFromTokenAction
{
    public function handle(string $plainTextToken): ?TenantInvitation
    {
        // queryWithoutTenantScope: accept links resolve before a tenant is current.
        return TenantInvitation::queryWithoutTenantScope()
            ->where('token', TenantInvitation::hashToken($plainTextToken))
            ->first();
    }
}
