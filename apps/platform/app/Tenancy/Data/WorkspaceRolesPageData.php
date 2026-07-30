<?php

declare(strict_types=1);

namespace App\Tenancy\Data;

use Illuminate\Contracts\Support\Arrayable;

/**
 * Full payload for the workspace roles Inertia page.
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class WorkspaceRolesPageData implements Arrayable
{
    /**
     * @param  list<WorkspaceRoleData>  $roles
     * @param  list<WorkspacePermissionGroupData>  $permissionGroups
     */
    public function __construct(
        public array $roles,
        public array $permissionGroups,
    ) {}

    /**
     * @return array{
     *     roles: list<array{
     *         value: string,
     *         label: string,
     *         is_system: bool,
     *         member_count: int
     *     }>,
     *     permission_groups: list<array{
     *         value: string,
     *         label: string,
     *         permissions: list<array{
     *             value: string,
     *             label: string,
     *             description: string,
     *             granted_to: list<string>
     *         }>
     *     }>
     * }
     */
    public function toArray(): array
    {
        return [
            'roles' => array_map(
                static fn (WorkspaceRoleData $role): array => $role->toArray(),
                $this->roles,
            ),
            'permission_groups' => array_map(
                static fn (WorkspacePermissionGroupData $group): array => $group->toArray(),
                $this->permissionGroups,
            ),
        ];
    }
}
