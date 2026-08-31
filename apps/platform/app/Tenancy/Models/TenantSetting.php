<?php

declare(strict_types=1);

namespace App\Tenancy\Models;

use Carbon\CarbonImmutable;
use Database\Factories\TenantSettingFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Override;

/**
 * @property int $id
 * @property int $tenant_id
 * @property bool $ai_document_overview_enabled
 * @property bool $ai_checklist_draft_enabled
 * @property bool $ai_reminder_draft_enabled
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Tenant $tenant
 *
 * @method static TenantSettingFactory factory($count = null, $state = [])
 * @method static Builder<static>|TenantSetting newModelQuery()
 * @method static Builder<static>|TenantSetting newQuery()
 * @method static Builder<static>|TenantSetting query()
 * @method static Builder<static>|TenantSetting whereAiChecklistDraftEnabled($value)
 * @method static Builder<static>|TenantSetting whereAiDocumentOverviewEnabled($value)
 * @method static Builder<static>|TenantSetting whereAiReminderDraftEnabled($value)
 * @method static Builder<static>|TenantSetting whereCreatedAt($value)
 * @method static Builder<static>|TenantSetting whereId($value)
 * @method static Builder<static>|TenantSetting whereTenantId($value)
 * @method static Builder<static>|TenantSetting whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
final class TenantSetting extends Model
{
    /** @use HasFactory<TenantSettingFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    #[Override]
    protected $fillable = [
        'tenant_id',
        'ai_document_overview_enabled',
        'ai_checklist_draft_enabled',
        'ai_reminder_draft_enabled',
    ];

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'ai_document_overview_enabled' => 'boolean',
            'ai_checklist_draft_enabled' => 'boolean',
            'ai_reminder_draft_enabled' => 'boolean',
        ];
    }
}
