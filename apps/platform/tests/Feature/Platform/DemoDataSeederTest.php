<?php

declare(strict_types=1);

use App\Accounts\Models\User;
use App\Dossiers\Enums\DossierStatus;
use App\Dossiers\Models\Dossier;
use App\Pipelines\Enums\PipelineStatus;
use App\Pipelines\Models\Pipeline;
use App\Reporting\Queries\FetchDashboardTrendQuery;
use App\Tenancy\Models\Tenant;
use App\Tenancy\TenantContext;
use Database\Seeders\LocalDatabaseSeeder;

// Seeders live under database/, outside phpunit.xml <source>app</source>.
// PHPUnit 13 warns "not a valid target for code coverage" and fails CI.

/**
 * The demo seeder backs live walkthroughs, so it fails in the worst possible
 * place: on a screen share, in front of an audience. These assertions cover the
 * parts that go quietly wrong when a schema or a dashboard query moves.
 */
beforeEach(function (): void {
    $this->seed(LocalDatabaseSeeder::class);

    $this->tenant = Tenant::query()->firstOrFail();
});

test('it seeds a workspace whose dashboard renders', function (): void {
    $owner = User::query()
        ->where('email', 'sanne@qompose-demo.nl')
        ->firstOrFail();

    $this->actingAs($owner)
        ->get("/workspaces/{$this->tenant->slug}/dashboard")
        ->assertOk();
});

test('every trend week carries throughput', function (): void {
    $points = resolve(TenantContext::class)->runForTenant(
        $this->tenant,
        fn (): array => resolve(FetchDashboardTrendQuery::class)->handle($this->tenant),
    );

    // Eight weeks is FetchDashboardTrendQuery's own window. Data created "now"
    // renders as seven zero columns and one spike, which reads worse on screen
    // than an empty chart — so assert every bucket, not just the total.
    expect($points)->toHaveCount(8);

    foreach ($points as $point) {
        expect($point->documentsReceived)->toBeGreaterThan(0);
    }
});

test('it seeds the dossier states the walkthrough depends on', function (): void {
    resolve(TenantContext::class)->runForTenant($this->tenant, function (): void {
        foreach (DossierStatus::cases() as $status) {
            expect(Dossier::query()->where('status', $status)->exists())
                ->toBeTrue("no dossier seeded in {$status->value}");
        }

        // The builder is demoed on a blank canvas.
        $emptyDraft = Dossier::query()
            ->where('status', DossierStatus::Draft)
            ->whereDoesntHave('documentRequests')
            ->exists();

        expect($emptyDraft)->toBeTrue();
    });
});

test('it seeds a failed pipeline so processing health is not an empty card', function (): void {
    resolve(TenantContext::class)->runForTenant($this->tenant, function (): void {
        expect(Pipeline::query()->where('status', PipelineStatus::Failed)->exists())->toBeTrue()
            ->and(Pipeline::query()->where('status', PipelineStatus::Completed)
                ->exists())
            ->toBeTrue();
    });
});

/**
 * Regression guard for a 500 the demo surfaced and the unit-level fixtures did not.
 *
 * StaffUploadedDocumentData asks the gate for `delete` on every upload, and
 * UploadedDocumentPolicy::delete() walks the upload's tenant, its parent request and
 * that request's dossier. None of those inverses arrive from loading the children, so
 * the page lazy loaded three relations per document.
 *
 * It hid because the failure is environment-shaped: Model::shouldBeStrict() is
 * unconditional, but automaticallyEagerLoadRelationships() runs only in production —
 * so production quietly paid extra queries while local, testing and staging threw.
 *
 * This lives here rather than in DossierShowPageTest because a factory-built dossier
 * does not reproduce it; only a fully seeded workspace does. Walking every seeded
 * dossier also means the demo cannot acquire a broken page without a test noticing.
 */
test('every seeded dossier staff page renders', function (): void {
    $owner = User::query()
        ->where('email', 'sanne@qompose-demo.nl')
        ->firstOrFail();

    $dossierIds = resolve(TenantContext::class)->runForTenant(
        $this->tenant,
        fn (): array => Dossier::query()->pluck('id')->all(),
    );

    expect($dossierIds)->not->toBeEmpty();

    // Overview, review and builder all feed MapStaffDocumentRequestRowsQuery.
    // Fixing only the overview left review returning 500, so assert all three:
    // a guard covering one caller of a shared mapper proves very little.
    foreach ($dossierIds as $dossierId) {
        $base = "/workspaces/{$this->tenant->slug}/dossiers/{$dossierId}";

        foreach (['', '/review', '/builder'] as $suffix) {
            $this->actingAs($owner)
                ->get($base.$suffix)
                ->assertOk();
        }
    }
});
