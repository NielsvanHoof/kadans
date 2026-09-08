<?php

declare(strict_types=1);

namespace App\Tenancy\Queries;

use App\Accounts\Models\User;
use App\Shared\Data\PersonOptionData;
use App\Tenancy\Enums\TenantMembershipStatus;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Support\StaffOptionsCache;
use Illuminate\Database\Eloquent\Builder;

/**
 * Active members for responsible-staff / actor filter dropdowns.
 *
 * Bounded and cached: the dossiers index toolbar and activity log actor
 * filter both hit this on every render, and a firm past the cap still
 * assigns from the members page.
 */
final class FetchResponsibleStaffOptionsQuery
{
    /**
     * Sized well above any realistic firm's headcount so the dropdown
     * stays a plain select, while still bounding the query.
     */
    private const int STAFF_OPTIONS_LIMIT = 500;

    /**
     * @return list<PersonOptionData>
     */
    public function handle(Tenant $tenant): array
    {
        $rows = StaffOptionsCache::remember(
            $tenant->id,
            fn (): array => $this->loadOptions($tenant),
        );

        /** @var list<PersonOptionData> $options */
        $options = [];

        foreach ($rows as $row) {
            $options[] = new PersonOptionData(
                id: $row['id'],
                name: $row['name'],
                email: $row['email'],
            );
        }

        return $options;
    }

    /**
     * @return list<array{id: int, name: string, email: string}>
     */
    private function loadOptions(Tenant $tenant): array
    {
        $userQuery = User::query()
            ->whereHas(
                'tenantMemberships',
                function (Builder $query) use ($tenant): void {
                    $query->getQuery()
                        ->where('tenant_id', $tenant->id)
                        ->where('status', TenantMembershipStatus::Active);
                },
            );

        $userQuery->getQuery()
            ->orderBy('name')
            ->limit(self::STAFF_OPTIONS_LIMIT);

        /** @var list<array{id: int, name: string, email: string}> $options */
        $options = [];

        foreach ($userQuery->get(['id', 'name', 'email']) as $user) {
            $options[] = [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ];
        }

        return $options;
    }
}
