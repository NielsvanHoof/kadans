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
     * Same ceiling as staff dropdown options — keeps reminder/portal fan-out
     * bounded on large firms instead of loading every active user into memory.
     */
    private const int ACTIVE_USERS_LIMIT = 500;

    /**
     * @return Collection<int, User>
     */
    public function handle(Tenant $tenant): Collection
    {
        $query = User::query()
            ->whereHas(
                'tenantMemberships',
                function (Builder $membershipQuery) use ($tenant): void {
                    $membershipQuery->getQuery()
                        ->where('tenant_id', $tenant->getKey())
                        ->where('status', TenantMembershipStatus::Active);
                },
            );

        // orderBy/limit via getQuery() for phpstan-strict-rules on Eloquent.
        $query->getQuery()
            ->orderBy('id')
            ->limit(self::ACTIVE_USERS_LIMIT);

        return $query->get();
    }
}
