<?php

declare(strict_types=1);

namespace App\Tenancy\Actions;

use App\Tenancy\Models\TenantInvitation;

final class ResolveTenantInvitationFromTokenAction
{
    public function handle(string $plainTextToken): ?TenantInvitation
    {
        // withoutTenantScope: accept links resolve before a tenant is current.
        return TenantInvitation::query()
            ->withoutTenantScope()
            ->where('token', TenantInvitation::hashToken($plainTextToken))
            ->first();
    }
}
