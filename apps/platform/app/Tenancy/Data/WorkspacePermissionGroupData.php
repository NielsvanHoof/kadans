<?php

declare(strict_types=1);

namespace App\Tenancy\Data;

/**
 * A titled block of permissions on the roles Inertia page.
 */
final readonly class WorkspacePermissionGroupData
{
    /**
     * @param  list<WorkspacePermissionData>  $permissions
     */
    public function __construct(
        public string $value,
        public string $label,
        public array $permissions,
    ) {}

    /**
     * @return array{
     *     value: string,
     *     label: string,
     *     permissions: list<array{
     *         value: string,
     *         label: string,
     *         description: string,
     *         granted_to: list<string>
     *     }>
     * }
     */
    public function toArray(): array
    {
        return [
            'value' => $this->value,
            'label' => $this->label,
            'permissions' => array_map(
                static fn (WorkspacePermissionData $permission): array => $permission->toArray(),
                $this->permissions,
            ),
        ];
    }
}
