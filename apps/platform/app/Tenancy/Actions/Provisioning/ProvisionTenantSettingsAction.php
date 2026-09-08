<?php

declare(strict_types=1);

namespace App\Tenancy\Actions\Provisioning;

use App\Tenancy\Models\Tenant;
use App\Tenancy\Models\TenantSetting;

final readonly class ProvisionTenantSettingsAction
{
    public function handle(Tenant $tenant): TenantSetting
    {
        return TenantSetting::query()->firstOrCreate(
            ['tenant_id' => $tenant->id],
            [
                'ai_document_overview_enabled' => true,
                'ai_checklist_draft_enabled' => true,
                'ai_reminder_draft_enabled' => true,
                'ai_document_search_enabled' => true,
            ],
        );
    }
}
