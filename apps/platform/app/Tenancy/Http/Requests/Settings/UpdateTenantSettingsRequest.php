<?php

declare(strict_types=1);

namespace App\Tenancy\Http\Requests\Settings;

use App\Shared\Http\Requests\Concerns\LocalizesValidationAttributes;
use App\Tenancy\Data\Settings\TenantAiSettingsData;
use App\Tenancy\Data\Settings\TenantSettingsData;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Queries\Settings\ResolveTenantSettingsQuery;
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

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'ai_document_overview_enabled' => ['required', 'boolean'],
            'ai_checklist_draft_enabled' => ['required', 'boolean'],
            'ai_reminder_draft_enabled' => ['required', 'boolean'],
            'ai_document_search_enabled' => ['required', 'boolean'],
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
        ];
    }

    public function settingsData(): TenantSettingsData
    {
        return new TenantSettingsData(
            ai: new TenantAiSettingsData(
                documentOverview: $this->boolean('ai_document_overview_enabled'),
                checklistDraft: $this->boolean('ai_checklist_draft_enabled'),
                reminderDraft: $this->boolean('ai_reminder_draft_enabled'),
                documentSearch: $this->boolean('ai_document_search_enabled'),
            ),
        );
    }
}
