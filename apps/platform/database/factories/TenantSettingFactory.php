<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Tenancy\Models\Tenant;
use App\Tenancy\Models\TenantSetting;
use Illuminate\Database\Eloquent\Factories\Factory;
use Override;

/**
 * @extends Factory<TenantSetting>
 */
final class TenantSettingFactory extends Factory
{
    #[Override]
    protected $model = TenantSetting::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'ai_document_overview_enabled' => true,
            'ai_checklist_draft_enabled' => true,
            'ai_reminder_draft_enabled' => true,
        ];
    }

    public function withAiDisabled(): static
    {
        return $this->state([
            'ai_document_overview_enabled' => false,
            'ai_checklist_draft_enabled' => false,
            'ai_reminder_draft_enabled' => false,
        ]);
    }
}
