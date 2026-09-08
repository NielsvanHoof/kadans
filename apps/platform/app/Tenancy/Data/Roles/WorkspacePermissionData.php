<?php

declare(strict_types=1);

namespace App\Tenancy\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One permission row on the roles Inertia page.
 *
 * `grantedTo` holds role names rather than a role-keyed map so the payload
 * stays flat: the matrix is rendered by testing membership of this list.
 */
#[TypeScript]
final class WorkspacePermissionData extends Data
{
    /**
     * @param  list<string>  $grantedTo
     */
    public function __construct(
        public readonly string $value,
        public readonly string $label,
        public readonly string $description,
        public readonly array $grantedTo,
    ) {}
}
