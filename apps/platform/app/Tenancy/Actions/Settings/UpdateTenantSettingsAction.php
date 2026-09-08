<?php

declare(strict_types=1);

namespace App\Tenancy\Actions\Settings;

use App\Tenancy\Data\Settings\TenantSettingsData;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Models\TenantSetting;
use App\Tenancy\Queries\Settings\ResolveTenantSettingsQuery;

final readonly class UpdateTenantSettingsAction
{
    public function __construct(private ResolveTenantSettingsQuery $resolveTenantSettings) {}

    public function handle(Tenant $tenant, TenantSettingsData $settings): TenantSettingsData
    {
        $model = TenantSetting::query()->firstOrCreate(
            ['tenant_id' => $tenant->id],
            [
                'ai_document_overview_enabled' => true,
                'ai_checklist_draft_enabled' => true,
                'ai_reminder_draft_enabled' => true,
                'ai_document_search_enabled' => true,
            ],
        );

        $model->update([
            'ai_document_overview_enabled' => $settings->ai->documentOverview,
            'ai_checklist_draft_enabled' => $settings->ai->checklistDraft,
            'ai_reminder_draft_enabled' => $settings->ai->reminderDraft,
            'ai_document_search_enabled' => $settings->ai->documentSearch,
        ]);

        return $this->resolveTenantSettings->handle($tenant);
    }
}
