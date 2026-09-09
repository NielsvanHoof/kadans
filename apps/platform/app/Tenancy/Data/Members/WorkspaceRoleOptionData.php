<?php

declare(strict_types=1);

namespace App\Tenancy\Data\Members;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Role select option shown when inviting or updating a member.
 */
#[TypeScript]
final class WorkspaceRoleOptionData extends Data
{
    public function __construct(
        public readonly string $value,
        public readonly string $label,
    ) {}
}
