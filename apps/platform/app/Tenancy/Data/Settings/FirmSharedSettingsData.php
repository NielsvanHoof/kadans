<?php

declare(strict_types=1);

namespace App\Tenancy\Data\Settings;

use App\Tenancy\Models\TenantSetting;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The slice of firm settings every staff member needs on every page.
 *
 * Kept deliberately small: this ships on every authenticated Inertia response,
 * to every member regardless of role. The AI flags hide affordances that would
 * otherwise call a disabled endpoint, and the reminder default prefills the new
 * dossier form. A firm\'s security, portal and retention policy is nobody
 * else\'s business and belongs on the settings page alone.
 */
#[TypeScript]
final class FirmSharedSettingsData extends Data
{
    public function __construct(
        public readonly TenantAiSettingsData $ai,
        public readonly TenantReminderSettingsData $reminders,
    ) {}

    public static function fromModel(?TenantSetting $settings): self
    {
        return new self(
            ai: TenantAiSettingsData::fromModel($settings),
            reminders: TenantReminderSettingsData::fromModel($settings),
        );
    }
}
