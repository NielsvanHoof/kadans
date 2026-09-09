<?php

declare(strict_types=1);

use App\Clients\Models\Client;
use App\Dossiers\Enums\DocumentRequestStatus;
use App\Dossiers\Enums\DossierStatus;
use App\Dossiers\Models\DocumentRequest;
use App\Dossiers\Models\Dossier;
use App\Reporting\Data\Dashboard\DashboardMetricsData;
use App\Reporting\Support\Dashboard\WorkspaceDashboardCache;
use App\Shared\Concerns\InvalidatesTenantCaches;
use App\Tenancy\Models\Tenant;

covers(InvalidatesTenantCaches::class);

function invalidationMetricsFixture(): DashboardMetricsData
{
    return new DashboardMetricsData(
        clients: 3,
        openDossiers: 2,
        needsReview: 1,
        awaitingClient: 4,
        overdue: 0,
        inReview: 1,
        submittedDocumentRequests: 5,
        outstandingDocumentRequests: 6,
        weeklyThroughput: [],
    );
}

/**
 * Warm the cache, perform the write, and report whether the entry survived.
 */
function dashboardCacheSurvives(Closure $write): bool
{
    $tenantId = (int) Tenant::current()?->getKey();

    WorkspaceDashboardCache::rememberMetrics($tenantId, invalidationMetricsFixture(...));

    $write();

    $recomputed = false;

    WorkspaceDashboardCache::rememberMetrics($tenantId, function () use (&$recomputed): DashboardMetricsData {
        $recomputed = true;

        return invalidationMetricsFixture();
    });

    return ! $recomputed;
}

beforeEach(function (): void {
    ['tenant' => $this->tenant] = provisionWorkspace();

    actingInWorkspace($this->tenant);

    $this->client = Client::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->dossier = Dossier::factory()->create([
        'tenant_id' => $this->tenant->id,
        'client_id' => $this->client->id,
        'status' => DossierStatus::AwaitingClient,
    ]);
    $this->documentRequest = DocumentRequest::factory()->create([
        'tenant_id' => $this->tenant->id,
        'dossier_id' => $this->dossier->id,
        'status' => DocumentRequestStatus::Pending,
    ]);
});

test('the harness itself does not evict', function (): void {
    expect(dashboardCacheSurvives(fn (): null => null))->toBeTrue();
});

test('a write that moves a cached metric evicts', function (Closure $write): void {
    expect(dashboardCacheSurvives($write))->toBeFalse();
})->with([
    'document request status' => [fn () => test()->documentRequest->update(['status' => DocumentRequestStatus::Submitted])],
    'dossier status' => [fn () => test()->dossier->update(['status' => DossierStatus::InReview])],
    'dossier due date' => [fn () => test()->dossier->update(['due_date' => today()->subDay()])],
    'a new client' => [fn () => Client::factory()->create(['tenant_id' => test()->tenant->id])],
    'a deleted client' => [fn () => test()->client->delete()],
]);

test('a write that moves nothing on the dashboard leaves it alone', function (Closure $write): void {
    // The point of the whole change: DocumentRequest is written on every client
    // answer and every staff review, so evicting on `saved` kept the sixteen
    // aggregate counts behind the dashboard permanently cold on a busy firm.
    expect(dashboardCacheSurvives($write))->toBeTrue();
})->with([
    'client name' => [fn () => test()->client->update(['name' => 'Renamed Firm'])],
    'dossier title' => [fn () => test()->dossier->update(['title' => 'Retitled'])],
    'document request title' => [fn () => test()->documentRequest->update(['title' => 'Retitled item'])],
    'document request sort order' => [fn () => test()->documentRequest->update(['sort_order' => 9])],
    'a no-op save' => [fn () => test()->documentRequest->save()],
]);
