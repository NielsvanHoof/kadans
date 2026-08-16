<?php

declare(strict_types=1);

use App\Reporting\Data\DashboardMetricsData;
use App\Reporting\Support\WorkspaceDashboardCache;
use Illuminate\Support\Facades\Cache;

covers(WorkspaceDashboardCache::class);

/*
|--------------------------------------------------------------------------
| Dashboard cache schema versioning
|--------------------------------------------------------------------------
|
| Entries are stored as plain arrays so a DTO class change cannot fail to
| unserialize — but fromArray() still reads every key it expects. Without a
| version segment in the key, a deploy that adds a metric would read entries
| written by the previous release, find the new key missing, and error for the
| rest of the TTL on every tenant already cached.
|
| The version segment turns that into a plain cache miss.
|
*/

/**
 * @return array<string, int>
 */
function dashboardMetricsFixture(int $clients = 3): array
{
    return new DashboardMetricsData(
        clients: $clients,
        openDossiers: 2,
        needsReview: 1,
        awaitingClient: 4,
        overdue: 0,
        inReview: 1,
        submittedDocumentRequests: 5,
        outstandingDocumentRequests: 6,
    )->toArray();
}

test('metrics survive a round trip through the cache', function (): void {
    $metrics = WorkspaceDashboardCache::rememberMetrics(
        1,
        fn (): DashboardMetricsData => DashboardMetricsData::fromArray(dashboardMetricsFixture()),
    );

    expect($metrics->clients)->toBe(3);

    // Second call must not re-run the callback.
    $cached = WorkspaceDashboardCache::rememberMetrics(
        1,
        fn (): DashboardMetricsData => throw new RuntimeException('callback should not run'),
    );

    expect($cached->clients)->toBe(3);
});

test('the cache key carries a schema version', function (): void {
    WorkspaceDashboardCache::rememberMetrics(
        7,
        fn (): DashboardMetricsData => DashboardMetricsData::fromArray(dashboardMetricsFixture()),
    );

    $key = sprintf('dashboard:metrics:v2:7:%s', today()->toDateString());

    expect(Cache::has($key))->toBeTrue();
});

test('an entry written under a previous schema version is ignored, not read', function (): void {
    // What the release before the version bump would have left behind: the old
    // key shape, holding an array that is missing the fields added since.
    Cache::put(
        sprintf('dashboard:metrics:7:%s', today()->toDateString()),
        ['clients' => 99],
        300,
    );

    $metrics = WorkspaceDashboardCache::rememberMetrics(
        7,
        fn (): DashboardMetricsData => DashboardMetricsData::fromArray(dashboardMetricsFixture(3)),
    );

    // Recomputed rather than exploding on the missing keys.
    expect($metrics->clients)->toBe(3);
});

test('forget clears the versioned key', function (): void {
    WorkspaceDashboardCache::rememberMetrics(
        7,
        fn (): DashboardMetricsData => DashboardMetricsData::fromArray(dashboardMetricsFixture()),
    );

    WorkspaceDashboardCache::forget(7);

    $recomputed = WorkspaceDashboardCache::rememberMetrics(
        7,
        fn (): DashboardMetricsData => DashboardMetricsData::fromArray(dashboardMetricsFixture(42)),
    );

    expect($recomputed->clients)->toBe(42);
});

test('metrics are cached per tenant', function (): void {
    WorkspaceDashboardCache::rememberMetrics(
        1,
        fn (): DashboardMetricsData => DashboardMetricsData::fromArray(dashboardMetricsFixture(3)),
    );

    $other = WorkspaceDashboardCache::rememberMetrics(
        2,
        fn (): DashboardMetricsData => DashboardMetricsData::fromArray(dashboardMetricsFixture(11)),
    );

    expect($other->clients)->toBe(11);
});
