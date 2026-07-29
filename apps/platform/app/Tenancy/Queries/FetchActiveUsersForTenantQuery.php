<?php

declare(strict_types=1);

namespace App\Tenancy\Queries;

use Illuminate\Contracts\Database\Query\Builder;
use App\Accounts\Models\User;
use App\Tenancy\Enums\TenantMembershipStatus;
use App\Tenancy\Models\Tenant;
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
                fn (Builder $query) => $query
                    ->where('tenant_id', $tenant->getKey())
                    ->where('status', TenantMembershipStatus::Active),
            )
            ->get();
    }
}
