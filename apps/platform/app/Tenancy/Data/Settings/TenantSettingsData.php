<?php

declare(strict_types=1);

namespace App\Tenancy\Data;

use App\Tenancy\Models\TenantSetting;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class TenantSettingsData extends Data
{
    public function __construct(
        public readonly TenantAiSettingsData $ai,
    ) {}

    public static function fromModel(TenantSetting $settings): self
    {
        return new self(
            ai: TenantAiSettingsData::fromModel($settings),
        );
    }
}
