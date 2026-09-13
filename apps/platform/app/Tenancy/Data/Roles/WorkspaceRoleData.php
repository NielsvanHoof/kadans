<?php

declare(strict_types=1);

namespace App\Tenancy\Data\Roles;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One role column on the roles Inertia page.
 */
#[TypeScript]
final class WorkspaceRoleData extends Data
{
    public function __construct(
        public readonly int $id,
        public readonly string $value,
        public readonly string $label,
        public readonly bool $isSystem,
        public readonly int $memberCount,
    ) {}
}
