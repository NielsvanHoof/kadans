<?php

declare(strict_types=1);

namespace App\Tenancy\Actions;

use App\Tenancy\Data\TenantSettingsData;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Models\TenantSetting;
use App\Tenancy\Queries\ResolveTenantSettingsQuery;

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
            ],
        );

        $model->update([
            'ai_document_overview_enabled' => $settings->ai->documentOverview,
            'ai_checklist_draft_enabled' => $settings->ai->checklistDraft,
            'ai_reminder_draft_enabled' => $settings->ai->reminderDraft,
        ]);

        return $this->resolveTenantSettings->handle($tenant);
    }
}
