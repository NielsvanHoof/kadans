<?php

declare(strict_types=1);

namespace App\Tenancy\Data;

use App\Tenancy\Models\TenantSetting;
use App\Tenancy\Settings\FirmSettingBounds;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class TenantReminderSettingsData extends Data
{
    public function __construct(
        public readonly FirmSettingData $defaultIntervalDays,
    ) {}

    public static function fromModel(?TenantSetting $settings): self
    {
        return new self(
            defaultIntervalDays: FirmSettingData::fromBound(
                FirmSettingBounds::reminderIntervalDays(),
                $settings?->default_reminder_interval_days,
            ),
        );
    }
}
