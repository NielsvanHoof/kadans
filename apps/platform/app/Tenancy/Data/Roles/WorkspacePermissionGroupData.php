<?php

declare(strict_types=1);

namespace App\Tenancy\Data\Roles;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A titled block of permissions on the roles Inertia page.
 */
#[TypeScript]
final class WorkspacePermissionGroupData extends Data
{
    /**
     * @param  list<WorkspacePermissionData>  $permissions
     */
    public function __construct(
        public readonly string $value,
        public readonly string $label,
        public readonly array $permissions,
    ) {}
}
