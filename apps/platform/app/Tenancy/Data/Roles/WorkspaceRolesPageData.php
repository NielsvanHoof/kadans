<?php

declare(strict_types=1);

namespace App\Tenancy\Data\Roles;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Full payload for the workspace roles Inertia page.
 */
#[TypeScript]
final class WorkspaceRolesPageData extends Data
{
    /**
     * @param  list<WorkspaceRoleData>  $roles
     * @param  list<WorkspacePermissionGroupData>  $permissionGroups
     */
    public function __construct(
        public readonly array $roles,
        public readonly array $permissionGroups,
        public readonly bool $canManageRoles,
    ) {}
}
