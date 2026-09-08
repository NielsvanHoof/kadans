<?php

declare(strict_types=1);

namespace App\Tenancy\Data\Workspace;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Workspace switcher entry shared via Inertia.
 */
#[TypeScript]
final class WorkspaceNavItemData extends Data
{
    public function __construct(
        public readonly string $name,
        public readonly string $slug,
    ) {}
}
