<?php

declare(strict_types=1);

namespace App\Tenancy\Data\Settings;

use App\Tenancy\Models\TenantSetting;
use App\Tenancy\Support\Settings\FirmSettingBounds;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class TenantPortalSettingsData extends Data
{
    public function __construct(
        public readonly FirmSettingData $sessionIdleMinutes,
        public readonly FirmSettingData $sessionAbsoluteMinutes,
    ) {}

    public static function fromModel(?TenantSetting $settings): self
    {
        return new self(
            sessionIdleMinutes: FirmSettingData::fromBound(
                FirmSettingBounds::portalSessionIdleMinutes(),
                $settings?->portal_session_idle_minutes,
            ),
            sessionAbsoluteMinutes: FirmSettingData::fromBound(
                FirmSettingBounds::portalSessionAbsoluteMinutes(),
                $settings?->portal_session_absolute_minutes,
            ),
        );
    }
}
