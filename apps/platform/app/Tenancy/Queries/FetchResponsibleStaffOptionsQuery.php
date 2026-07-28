<?php

declare(strict_types=1);

namespace App\Tenancy\Queries;

use App\Accounts\Models\User;
use App\Shared\Data\PersonOptionData;
use App\Tenancy\Enums\TenantMembershipStatus;
use App\Tenancy\Models\Tenant;

final class FetchResponsibleStaffOptionsQuery
{
    /**
     * @return list<PersonOptionData>
     */
    public function handle(Tenant $tenant): array
    {
        /** @var list<PersonOptionData> $options */
        $options = [];

        foreach (
            User::query()
                ->whereHas(
                    'tenantMemberships',
                    fn ($query) => $query
                        ->where('tenant_id', $tenant->id)
                        ->where('status', TenantMembershipStatus::Active),
                )
                ->oldest('name')
                ->get(['id', 'name', 'email']) as $user
        ) {
            $options[] = new PersonOptionData(
                id: $user->id,
                name: $user->name,
                email: $user->email,
            );
        }

        return $options;
    }
}
