<?php

declare(strict_types=1);

namespace App\Tenancy\Http\Requests\Settings;

use App\Accounts\Enums\TwoFactorEnforcement;
use App\Shared\Http\Requests\Concerns\LocalizesValidationAttributes;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Queries\Settings\ResolveTenantSettingsQuery;
use App\Tenancy\Support\Settings\FirmSettingBounds;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Override;

final class UpdateTenantSettingsRequest extends FormRequest
{
    use LocalizesValidationAttributes;

    public function authorize(): bool
    {
        $tenant = $this->route('tenant');

        if (! $tenant instanceof Tenant) {
            return false;
        }

        $settings = resolve(ResolveTenantSettingsQuery::class)->forTenant($tenant);

        return $this->user()?->can('update', $settings) ?? false;
    }

    /**
     * Every bound comes from FirmSettingBounds, which the read-time clamp uses
     * too. A rule typed in by hand here would be a rule the resolver disagrees
     * with the first time either changes.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'ai_document_overview_enabled' => ['required', 'boolean'],
            'ai_checklist_draft_enabled' => ['required', 'boolean'],
            'ai_reminder_draft_enabled' => ['required', 'boolean'],
            'ai_document_search_enabled' => ['required', 'boolean'],
            'two_factor_enforcement' => FirmSettingBounds::twoFactorEnforcement()->rules(),
            'portal_session_idle_minutes' => FirmSettingBounds::portalSessionIdleMinutes()->rules(),
            'portal_session_absolute_minutes' => FirmSettingBounds::portalSessionAbsoluteMinutes()->rules(),
            'default_reminder_interval_days' => FirmSettingBounds::reminderIntervalDays()->rules(),
            'retention_archived_days' => FirmSettingBounds::retentionArchivedDays()->rules(),
            'retention_activity_log_days' => FirmSettingBounds::retentionActivityLogDays()->rules(),
        ];
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    public function messages(): array
    {
        return [
            'two_factor_enforcement.in' => __('Your firm can ask more of your team than the standard setting, but not less.'),
            'portal_session_idle_minutes.max' => __('Client portal sessions can be shorter than the standard setting, but not longer.'),
            'portal_session_absolute_minutes.max' => __('Client portal sessions can be shorter than the standard setting, but not longer.'),
        ];
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    public function attributes(): array
    {
        return [
            'ai_document_overview_enabled' => __('Document overview'),
            'ai_checklist_draft_enabled' => __('Checklist suggestions'),
            'ai_reminder_draft_enabled' => __('Reminder drafts'),
            'ai_document_search_enabled' => __('Document search'),
            'two_factor_enforcement' => __('Two-step sign-in'),
            'portal_session_idle_minutes' => __('Inactive sign-out'),
            'portal_session_absolute_minutes' => __('Maximum session length'),
            'default_reminder_interval_days' => __('Default reminder interval'),
            'retention_archived_days' => __('Archived record retention'),
            'retention_activity_log_days' => __('Activity history retention'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function settingsAttributes(): array
    {
        return [
            'ai_document_overview_enabled' => $this->boolean('ai_document_overview_enabled'),
            'ai_checklist_draft_enabled' => $this->boolean('ai_checklist_draft_enabled'),
            'ai_reminder_draft_enabled' => $this->boolean('ai_reminder_draft_enabled'),
            'ai_document_search_enabled' => $this->boolean('ai_document_search_enabled'),
            'two_factor_enforcement' => TwoFactorEnforcement::tryFrom(
                (string) $this->validated('two_factor_enforcement'),
            ),
            'portal_session_idle_minutes' => $this->integerOrNull('portal_session_idle_minutes'),
            'portal_session_absolute_minutes' => $this->integerOrNull('portal_session_absolute_minutes'),
            'default_reminder_interval_days' => $this->integerOrNull('default_reminder_interval_days'),
            'retention_archived_days' => $this->integerOrNull('retention_archived_days'),
            'retention_activity_log_days' => $this->integerOrNull('retention_activity_log_days'),
        ];
    }

    /**
     * An empty field means "inherit the platform value", so it must persist as
     * null rather than as a zero.
     */
    private function integerOrNull(string $key): ?int
    {
        $value = $this->validated($key);

        return $value === null || $value === '' ? null : (int) $value;
    }
}
