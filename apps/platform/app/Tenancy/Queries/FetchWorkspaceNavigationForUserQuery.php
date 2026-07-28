<?php

declare(strict_types=1);

namespace App\Tenancy\Queries;

use App\Accounts\Models\User;
use App\Tenancy\Data\WorkspaceNavItemData;
use App\Tenancy\Enums\TenantMembershipStatus;
use App\Tenancy\Models\Tenant;

final class FetchWorkspaceNavigationForUserQuery
{
    /**
     * @return list<WorkspaceNavItemData>
     */
    public function handle(User $user): array
    {
        /** @var list<WorkspaceNavItemData> $workspaces */
        $workspaces = [];

        foreach (
            Tenant::query()
                ->whereHas('memberships', fn ($query) => $query
                    ->where('user_id', $user->id)
                    ->where('status', TenantMembershipStatus::Active))
                ->get(['name', 'slug'])
                ->sortBy('name')
                ->values() as $tenant
        ) {
            $workspaces[] = new WorkspaceNavItemData(
                name: $tenant->name,
                slug: $tenant->slug,
            );
        }

        return $workspaces;
    }
}
