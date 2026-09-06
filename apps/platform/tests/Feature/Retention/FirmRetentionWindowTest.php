<?php

declare(strict_types=1);

use App\Audit\Models\Activity;
use App\Clients\Models\Client;
use App\Dossiers\Models\Dossier;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Models\TenantSetting;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('local');
    config([
        'retention.archived_days' => 2555,
        'retention.activity_log_days' => 2555,
        'retention.tenant_bounds.archived_days' => ['min' => 365, 'max' => 3650],
        'retention.tenant_bounds.activity_log_days' => ['min' => 365, 'max' => 3650],
    ]);
});

function setFirmRetentionWindow(Tenant $tenant, ?int $archivedDays, ?int $activityLogDays = null): void
{
    TenantSetting::query()->where('tenant_id', $tenant->id)->update([
        'retention_archived_days' => $archivedDays,
        'retention_activity_log_days' => $activityLogDays,
    ]);
}

/**
 * Dossier is tenant-scoped, so a plain query here would silently only see the
 * firm that happens to be current and report every other firm's rows as gone.
 */
function dossierStillExists(int $dossierId): bool
{
    return Dossier::queryWithoutTenantScope()->withTrashed()->whereKey($dossierId)->exists();
}

function activityStillExists(int $activityId): bool
{
    return Activity::queryAcrossTenants()->whereKey($activityId)->exists();
}

function archiveDossierFor(Tenant $tenant, int $deletedDaysAgo): Dossier
{
    return resolve(TenantContext::class)->runForTenant($tenant, function () use ($tenant, $deletedDaysAgo): Dossier {
        $client = Client::factory()->create(['tenant_id' => $tenant->id]);
        $dossier = Dossier::factory()->create([
            'tenant_id' => $tenant->id,
            'client_id' => $client->id,
        ]);
        $dossier->delete();
        $dossier->forceFill(['deleted_at' => now()->subDays($deletedDaysAgo)])->saveQuietly();

        return $dossier;
    });
}

/**
 * One sweep, two firms, two different cutoffs. A single platform-wide date
 * would either spare the firm that asked for a shorter window or purge the one
 * that asked for a longer one — both are the firm's decision to make.
 */
test('two firms are purged against their own retention windows in one run', function (): void {
    ['tenant' => $shortTenant] = provisionWorkspace();
    ['tenant' => $longTenant] = provisionWorkspace();

    setFirmRetentionWindow($shortTenant, archivedDays: 365);
    setFirmRetentionWindow($longTenant, archivedDays: 3650);

    $shortDossier = archiveDossierFor($shortTenant, deletedDaysAgo: 400);
    $longDossier = archiveDossierFor($longTenant, deletedDaysAgo: 400);

    $this->artisan('retention:purge-legal')->assertSuccessful();

    expect(dossierStillExists($shortDossier->id))->toBeFalse()
        ->and(dossierStillExists($longDossier->id))->toBeTrue();
});

test('a firm that has chosen nothing is purged on the platform window', function (): void {
    ['tenant' => $tenant] = provisionWorkspace();
    setFirmRetentionWindow($tenant, archivedDays: null);

    $withinWindow = archiveDossierFor($tenant, deletedDaysAgo: 2000);
    $pastWindow = archiveDossierFor($tenant, deletedDaysAgo: 2600);

    $this->artisan('retention:purge-legal')->assertSuccessful();

    expect(dossierStillExists($withinWindow->id))->toBeTrue()
        ->and(dossierStillExists($pastWindow->id))->toBeFalse();
});

test('a firm with no settings row does not abort the sweep for the others', function (): void {
    ['tenant' => $brokenTenant] = provisionWorkspace();
    ['tenant' => $healthyTenant] = provisionWorkspace();

    TenantSetting::query()->where('tenant_id', $brokenTenant->id)->delete();
    setFirmRetentionWindow($healthyTenant, archivedDays: 365);

    $brokenDossier = archiveDossierFor($brokenTenant, deletedDaysAgo: 400);
    $healthyDossier = archiveDossierFor($healthyTenant, deletedDaysAgo: 400);

    $this->artisan('retention:purge-legal')->assertSuccessful();

    expect(dossierStillExists($healthyDossier->id))->toBeFalse()
        // Falls back to the platform window rather than blowing up the run.
        ->and(dossierStillExists($brokenDossier->id))->toBeTrue();
});

test('activity history is pruned on each firm’s own window', function (): void {
    ['tenant' => $shortTenant] = provisionWorkspace();
    ['tenant' => $longTenant] = provisionWorkspace();

    setFirmRetentionWindow($shortTenant, archivedDays: null, activityLogDays: 365);
    setFirmRetentionWindow($longTenant, archivedDays: null, activityLogDays: 3650);

    $rowFor = function (Tenant $tenant): int {
        $activity = Activity::query()->forceCreate([
            'tenant_id' => $tenant->id,
            'log_name' => 'default',
            'description' => 'old entry',
            'created_at' => now()->subDays(400),
            'updated_at' => now()->subDays(400),
        ]);

        return (int) $activity->getKey();
    };

    $shortRow = $rowFor($shortTenant);
    $longRow = $rowFor($longTenant);

    $this->artisan('retention:prune-activity-log')->assertSuccessful();

    expect(activityStillExists($shortRow))->toBeFalse()
        ->and(activityStillExists($longRow))->toBeTrue();
});

test('a retention window outside the band is refused on save', function (int $days): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();

    $this->actingAs($owner)
        ->patch(workspaceRoute('workspaces.settings.update', $tenant), [
            'ai_document_overview_enabled' => true,
            'ai_checklist_draft_enabled' => true,
            'ai_reminder_draft_enabled' => true,
            'retention_archived_days' => $days,
        ])
        ->assertSessionHasErrors('retention_archived_days');

    expect(TenantSetting::query()->where('tenant_id', $tenant->id)->sole())
        ->retention_archived_days->toBeNull();
})->with(['under a year' => 200, 'over a decade' => 4000]);
