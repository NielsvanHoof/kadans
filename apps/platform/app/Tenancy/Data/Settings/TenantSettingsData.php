<?php

declare(strict_types=1);

namespace App\Tenancy\Data\Settings;

use App\Tenancy\Models\TenantSetting;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Every firm setting, resolved and clamped.
 *
 * This is the single construction site every consumer passes through, so the
 * clamp applied by each section is the only thing standing between a row edited
 * directly in the database and the code that acts on it. Nothing downstream
 * ever sees a raw column value.
 *
 * Only the firm settings page receives all of this. The shared prop that every
 * authenticated response carries is FirmSharedSettingsData, deliberately much
 * smaller.
 */
#[TypeScript]
final class TenantSettingsData extends Data
{
    public function __construct(
        public readonly TenantAiSettingsData $ai,
        public readonly TenantSecuritySettingsData $security,
        public readonly TenantPortalSettingsData $portal,
        public readonly TenantReminderSettingsData $reminders,
        public readonly TenantRetentionSettingsData $retention,
    ) {}

    public static function fromModel(?TenantSetting $settings): self
    {
        return new self(
            ai: TenantAiSettingsData::fromModel($settings),
            security: TenantSecuritySettingsData::fromModel($settings),
            portal: TenantPortalSettingsData::fromModel($settings),
            reminders: TenantReminderSettingsData::fromModel($settings),
            retention: TenantRetentionSettingsData::fromModel($settings),
        );
    }
}
