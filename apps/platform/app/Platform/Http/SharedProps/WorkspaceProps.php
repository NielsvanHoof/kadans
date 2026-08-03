<?php

declare(strict_types=1);

namespace App\Platform\Http\SharedProps;

use App\Accounts\Models\User;
use App\Tenancy\Data\WorkspaceNavItemData;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Queries\FetchWorkspaceNavigationForUserQuery;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * The firm switcher list and the currently active firm.
 */
final readonly class WorkspaceProps
{
    public function __construct(
        private FetchWorkspaceNavigationForUserQuery $fetchWorkspaceNavigationForUser,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function __invoke(Request $request, ?User $user): array
    {
        return [
            'workspaces' => $user instanceof User
                ? Inertia::once(
                    fn (): array => array_map(
                        static fn (WorkspaceNavItemData $item): array => $item->toArray(),
                        $this->fetchWorkspaceNavigationForUser->handle($user),
                    ),
                )->fresh(
                    $request->hasSession()
                        ? $request->session()->pull('inertia.refresh.workspaces', false)
                        : false,
                )
                : [],
            'current_firm' => function (): ?array {
                $tenant = Tenant::current();

                if (! $tenant instanceof Tenant) {
                    return null;
                }

                return [
                    'name' => $tenant->name,
                    'slug' => $tenant->slug,
                ];
            },
        ];
    }
}
