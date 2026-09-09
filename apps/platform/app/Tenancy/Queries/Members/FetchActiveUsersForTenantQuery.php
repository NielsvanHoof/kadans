<?php

declare(strict_types=1);

namespace App\Tenancy\Queries\Members;

use App\Accounts\Models\User;
use App\Tenancy\Enums\TenantMembershipStatus;
use App\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Active staff members of a workspace (for broadcasting inbox notifications).
 */
final class FetchActiveUsersForTenantQuery
{
    /**
     * @return Collection<int, User>
     */
    public function handle(Tenant $tenant): Collection
    {
        return User::query()
            ->whereHas(
                'tenantMemberships',
                function (Builder $query) use ($tenant): void {
                    $query->getQuery()
                        ->where('tenant_id', $tenant->getKey())
                        ->where('status', TenantMembershipStatus::Active);
                },
            )
            ->get();
    }
}
