<?php

declare(strict_types=1);

namespace App\Tenancy\Data\Settings;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class WorkspaceSettingsPageData extends Data
{
    public function __construct(
        public readonly TenantSettingsData $settings,
    ) {}
}
