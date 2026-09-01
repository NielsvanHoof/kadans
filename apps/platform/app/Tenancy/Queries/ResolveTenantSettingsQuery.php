<?php

declare(strict_types=1);

namespace App\Tenancy\Queries;

use App\Tenancy\Data\TenantSettingsData;
use App\Tenancy\Enums\TenantAiFeature;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Models\TenantSetting;

final readonly class ResolveTenantSettingsQuery
{
    /**
     * @var array<string, bool>
     */
    private const array DEFAULTS = [
        'ai_document_overview_enabled' => true,
        'ai_checklist_draft_enabled' => true,
        'ai_reminder_draft_enabled' => true,
    ];

    public function handle(?Tenant $tenant = null): TenantSettingsData
    {
        return TenantSettingsData::fromModel($this->forTenant($tenant));
    }

    public function isAiFeatureEnabled(TenantAiFeature $feature, ?Tenant $tenant = null): bool
    {
        $tenant ??= Tenant::current();

        if (! $tenant instanceof Tenant) {
            return true;
        }

        return $feature->isEnabledOn($this->forTenant($tenant));
    }

    public function forTenant(?Tenant $tenant = null): TenantSetting
    {
        $tenant ??= Tenant::current();

        abort_unless($tenant instanceof Tenant, 404);

        return TenantSetting::query()->firstOrCreate(
            ['tenant_id' => $tenant->id],
            self::DEFAULTS,
        );
    }
}
