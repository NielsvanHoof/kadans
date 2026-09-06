<?php

declare(strict_types=1);

namespace App\Tenancy\Models;

use App\Accounts\Enums\TwoFactorEnforcement;
use App\Tenancy\Support\TenantSettingsCache;
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
 * @property TwoFactorEnforcement|null $two_factor_enforcement
 * @property int|null $two_factor_grace_days
 * @property int|null $portal_session_idle_minutes
 * @property int|null $portal_session_absolute_minutes
 * @property int|null $default_reminder_interval_days
 * @property int|null $retention_archived_days
 * @property int|null $retention_activity_log_days
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
        'two_factor_enforcement',
        'two_factor_grace_days',
        'portal_session_idle_minutes',
        'portal_session_absolute_minutes',
        'default_reminder_interval_days',
        'retention_archived_days',
        'retention_activity_log_days',
    ];

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Invalidate from here rather than from the write actions: one site cannot
     * be forgotten by whoever adds the next setting. The reason
     * StaffOptionsCache avoids model events — `last_accessed_at` writing every
     * five minutes — has no equivalent here, since this row changes only when
     * an administrator saves the settings page or a firm is provisioned.
     */
    #[Override]
    protected static function booted(): void
    {
        self::saved(static function (TenantSetting $settings): void {
            TenantSettingsCache::forget($settings->tenant_id);
        });

        self::deleted(static function (TenantSetting $settings): void {
            TenantSettingsCache::forget($settings->tenant_id);
        });
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
            'two_factor_enforcement' => TwoFactorEnforcement::class,
            'two_factor_grace_days' => 'integer',
            'portal_session_idle_minutes' => 'integer',
            'portal_session_absolute_minutes' => 'integer',
            'default_reminder_interval_days' => 'integer',
            'retention_archived_days' => 'integer',
            'retention_activity_log_days' => 'integer',
        ];
    }
}
