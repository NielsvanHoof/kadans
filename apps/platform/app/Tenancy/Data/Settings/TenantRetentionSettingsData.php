<?php

declare(strict_types=1);

namespace App\Tenancy\Data\Settings;

use App\Tenancy\Models\TenantSetting;
use App\Tenancy\Support\Settings\FirmSettingBounds;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class TenantRetentionSettingsData extends Data
{
    public function __construct(
        public readonly FirmSettingData $archivedDays,
        public readonly FirmSettingData $activityLogDays,
    ) {}

    public static function fromModel(?TenantSetting $settings): self
    {
        return new self(
            archivedDays: FirmSettingData::fromBound(
                FirmSettingBounds::retentionArchivedDays(),
                $settings?->retention_archived_days,
            ),
            activityLogDays: FirmSettingData::fromBound(
                FirmSettingBounds::retentionActivityLogDays(),
                $settings?->retention_activity_log_days,
            ),
        );
    }
}
