<?php

declare(strict_types=1);

use App\Tenancy\Models\Tenant;
use App\Tenancy\Models\TenantSetting;
use App\Tenancy\Queries\ResolveTenantSettingsQuery;
use App\Tenancy\Support\TenantSettingsCache;
use Illuminate\Support\Facades\DB;

covers(ResolveTenantSettingsQuery::class);

/**
 * @return array{0: mixed, 1: int}
 */
function countingQueries(Closure $callback): array
{
    $count = 0;
    DB::listen(function () use (&$count): void {
        $count++;
    });

    $result = $callback();

    return [$result, $count];
}

test('repeated reads in one request hit the database once', function (): void {
    ['tenant' => $tenant] = provisionWorkspace();
    $query = resolve(ResolveTenantSettingsQuery::class);
    TenantSettingsCache::forget($tenant->id);

    [, $queryCount] = countingQueries(function () use ($query, $tenant): void {
        $query->forTenantOrNull($tenant);
        $query->forTenantOrNull($tenant);
        $query->forTenantOrNull($tenant);
    });

    expect($queryCount)->toBe(1);
});

test('saving settings makes the next read return the new value immediately', function (): void {
    ['tenant' => $tenant] = provisionWorkspace();
    $query = resolve(ResolveTenantSettingsQuery::class);

    expect($query->handle($tenant)->ai->checklistDraft)->toBeTrue();

    $query->forTenant($tenant)->update(['ai_checklist_draft_enabled' => false]);

    expect($query->handle($tenant)->ai->checklistDraft)->toBeFalse();
});

test('reading settings never inserts a row', function (): void {
    ['tenant' => $tenant] = provisionWorkspace();
    TenantSetting::query()->where('tenant_id', $tenant->id)->delete();

    $query = resolve(ResolveTenantSettingsQuery::class);

    expect($query->forTenantOrNull($tenant))->toBeNull()
        ->and($query->handle($tenant)->ai->documentOverview)->toBeTrue();

    $this->assertDatabaseCount('tenant_settings', 0);
});

test('reading settings without a current tenant returns defaults rather than aborting', function (): void {
    Tenant::forgetCurrent();

    $query = resolve(ResolveTenantSettingsQuery::class);

    expect($query->forTenantOrNull())->toBeNull()
        ->and($query->handle()->ai->reminderDraft)->toBeTrue()
        ->and($query->defaultReminderIntervalDays())->toBeNull();
});

test('the cache key carries the schema version and the tenant', function (): void {
    expect(TenantSettingsCache::key(7))->toBe('tenant-settings:v2:7')
        ->and(TenantSettingsCache::key(8))->not->toBe(TenantSettingsCache::key(7));
});
