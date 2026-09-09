<?php

declare(strict_types=1);

namespace App\Tenancy\Queries\Settings;

use App\Accounts\Enums\TwoFactorEnforcement;
use App\Tenancy\Data\Settings\FirmSharedSettingsData;
use App\Tenancy\Data\Settings\TenantSettingsData;
use App\Tenancy\Enums\TenantAiFeature;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Models\TenantSetting;
use App\Tenancy\Support\Settings\FirmSettingBounds;
use App\Tenancy\Support\Settings\TenantSettingsCache;

/**
 * The single read path for firm settings.
 *
 * Split into a read side and a write side on purpose. The read side is called
 * from authentication middleware on every workspace request, from the shared
 * Inertia props on every authenticated response, from the client portal, and
 * from console commands that iterate every tenant — so it must not write, and
 * it must not abort. `forTenant()` keeps both behaviours for the two callers
 * that legitimately want them.
 */
final readonly class ResolveTenantSettingsQuery
{
    /**
     * @var array<string, bool>
     */
    private const array DEFAULTS = [
        'ai_document_overview_enabled' => true,
        'ai_checklist_draft_enabled' => true,
        'ai_reminder_draft_enabled' => true,
        'ai_document_search_enabled' => true,
    ];

    public function handle(?Tenant $tenant = null): TenantSettingsData
    {
        return TenantSettingsData::fromModel($this->forTenantOrNull($tenant));
    }

    public function shared(?Tenant $tenant = null): FirmSharedSettingsData
    {
        return FirmSharedSettingsData::fromModel($this->forTenantOrNull($tenant));
    }

    public function isAiFeatureEnabled(TenantAiFeature $feature, ?Tenant $tenant = null): bool
    {
        $settings = $this->forTenantOrNull($tenant);

        // No tenant and no row both mean "nothing has opted out", which is the
        // shipped default for every AI feature.
        return ! $settings instanceof TenantSetting || $feature->isEnabledOn($settings);
    }

    /**
     * Already clamped: the result is never weaker than the platform config, so
     * a row edited outside the form cannot lower a firm's enforcement.
     */
    public function twoFactorEnforcement(?Tenant $tenant = null): TwoFactorEnforcement
    {
        return FirmSettingBounds::twoFactorEnforcement()->resolve(
            $this->forTenantOrNull($tenant)?->two_factor_enforcement,
        );
    }

    /**
     * Keyed by tenant id rather than the current tenant: the portal stamps its
     * session window at link exchange, which runs outside the middleware that
     * makes a tenant current.
     */
    public function portalSessionIdleMinutes(int $tenantId): int
    {
        return (int) FirmSettingBounds::portalSessionIdleMinutes()->resolve(
            $this->forTenantIdOrNull($tenantId)?->portal_session_idle_minutes,
        );
    }

    public function portalSessionAbsoluteMinutes(int $tenantId): int
    {
        return (int) FirmSettingBounds::portalSessionAbsoluteMinutes()->resolve(
            $this->forTenantIdOrNull($tenantId)?->portal_session_absolute_minutes,
        );
    }

    /**
     * Null when the firm has set no house rhythm — unlike every other setting,
     * this one has no platform default to fall back to.
     */
    public function defaultReminderIntervalDays(?Tenant $tenant = null): ?int
    {
        return FirmSettingBounds::reminderIntervalDays()->resolve(
            $this->forTenantOrNull($tenant)?->default_reminder_interval_days,
        );
    }

    public function retentionArchivedDays(?Tenant $tenant = null): int
    {
        return (int) FirmSettingBounds::retentionArchivedDays()->resolve(
            $this->forTenantOrNull($tenant)?->retention_archived_days,
        );
    }

    public function retentionActivityLogDays(?Tenant $tenant = null): int
    {
        return (int) FirmSettingBounds::retentionActivityLogDays()->resolve(
            $this->forTenantOrNull($tenant)?->retention_activity_log_days,
        );
    }

    /**
     * Read-only and safe everywhere: no tenant, or no row yet, is null rather
     * than an abort or an insert.
     */
    public function forTenantOrNull(?Tenant $tenant = null): ?TenantSetting
    {
        $tenant ??= Tenant::current();

        return $tenant instanceof Tenant
            ? $this->forTenantIdOrNull($tenant->id)
            : null;
    }

    public function forTenantIdOrNull(int $tenantId): ?TenantSetting
    {
        $attributes = TenantSettingsCache::remember(
            $tenantId,
            static fn (): ?array => TenantSetting::query()
                ->where('tenant_id', $tenantId)
                ->first()?->getAttributes(),
        );

        return $attributes === null
            ? null
            : (new TenantSetting)->newFromBuilder($attributes);
    }

    /**
     * The write path: creates the row if it is missing and requires a tenant.
     *
     * Only the settings page needs this — a form request resolving a model for
     * the policy, and the action that saves it. Everything else reads.
     */
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
