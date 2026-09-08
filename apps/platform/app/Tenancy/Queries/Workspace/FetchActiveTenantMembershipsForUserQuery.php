<?php

declare(strict_types=1);

namespace App\Tenancy\Queries\Workspace;

use App\Accounts\Models\User;
use App\Tenancy\Enums\TenantMembershipStatus;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Models\TenantMembership;
use Illuminate\Database\Eloquent\Collection;
use RuntimeException;

final class FetchActiveTenantMembershipsForUserQuery
{
    /**
     * @return Collection<int, TenantMembership>
     */
    public function handle(User $user): Collection
    {
        return TenantMembership::query()
            ->with('tenant:id,name,slug')
            ->whereBelongsTo($user)
            ->where('status', TenantMembershipStatus::Active)
            ->get()
            ->sortBy(
                fn (TenantMembership $membership): string => $this->resolveTenant($membership)->name,
            )
            ->values();
    }

    private function resolveTenant(TenantMembership $membership): Tenant
    {
        $tenant = $membership->tenant;

        throw_unless($tenant instanceof Tenant, RuntimeException::class, 'Tenant membership is missing its tenant.');

        return $tenant;
    }
}
