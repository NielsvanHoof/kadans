<?php

declare(strict_types=1);

namespace App\Reporting\Support\Dashboard;

use App\Reporting\Data\Dashboard\DashboardMetricsData;
use Closure;
use Illuminate\Support\Facades\Cache;

use function is_array;
use function sprintf;

/**
 * Tenant-scoped cache for workspace dashboard aggregate metrics.
 *
 * Keys include the tenant id explicitly because PrefixCacheTask is disabled
 * in config/multitenancy.php — without it, firms would share cache entries.
 *
 * The date segment rolls over at midnight so time-relative metrics (overdue,
 * weekly trends) stay correct even when no data mutates.
 *
 * Two things must never be stored here:
 *
 * Per-user data. The key has no user segment, so the first member to load the
 * dashboard would populate the blob and every other member of the firm would
 * read that person's numbers until the TTL expired. My-work counters therefore
 * bypass this cache entirely.
 *
 * Pipeline and portal-grant data. Only Client, Dossier and DocumentRequest use
 * InvalidatesTenantCaches, so a failed job or a client opening their link would
 * not evict the entry. Adding the trait to those models would be worse — both
 * write far more often than dossiers do, and each write would throw away the
 * expensive aggregate counts this cache exists to hold. Those widgets use
 * Inertia::defer() and stay live.
 */
final class WorkspaceDashboardCache
{
    private const int TTL_SECONDS = 300;

    /**
     * How long a stored entry is served without question.
     *
     * Past this the entry is still returned, but one request refreshes it after
     * the response is sent. Without that, every concurrent dashboard load at the
     * moment of expiry runs getMetrics() — sixteen aggregate counts, four of them
     * whereHas subqueries — at the same time.
     */
    private const int FRESH_SECONDS = 120;

    /**
     * Bump whenever DashboardMetricsData gains, drops, or renames a field.
     *
     * Storing a plain array keeps a shape change from failing to unserialize, but
     * fromArray() still reads every key it expects: without a version segment, a
     * deploy that adds a metric reads pre-deploy entries that lack it and the
     * dashboard errors for the rest of the TTL, for every tenant already cached.
     * A new segment simply misses instead, and the old entries age out on TTL.
     */
    private const string SCHEMA_VERSION = 'v3';

    /**
     * @param  Closure(): DashboardMetricsData  $callback
     */
    public static function rememberMetrics(int $tenantId, Closure $callback): DashboardMetricsData
    {
        // flexible() rather than get()/put(): it takes a lock around the
        // recompute and serves the stale entry to everyone else meanwhile, so a
        // busy firm pays for one recompute instead of one per in-flight request.
        //
        // The callback must stay a closure. `$callback()->toArray(...)` looks
        // like a tidier first-class callable and is not one: that form evaluates
        // `$callback()` immediately to find the receiver, so the sixteen
        // aggregate queries this cache exists to avoid would run on every call,
        // hit or miss. DashboardCacheInvalidationTest is what catches it.
        $cached = Cache::flexible(
            self::key($tenantId),
            [self::FRESH_SECONDS, self::TTL_SECONDS],
            // Plain array, not a serialized DTO: the class shape is free to
            // move between deploys. SCHEMA_VERSION covers the array's shape.
            static fn (): array => $callback()->toArray(),
        );

        if (is_array($cached)) {
            /** @var array{
             *     clients: int,
             *     open_dossiers: int,
             *     needs_review: int,
             *     awaiting_client: int,
             *     overdue: int,
             *     in_review: int,
             *     submitted_document_requests: int,
             *     outstanding_document_requests: int,
             *     open_dossiers_this_week: int,
             *     open_dossiers_last_week: int,
             *     needs_review_this_week: int,
             *     needs_review_last_week: int,
             *     awaiting_client_this_week: int,
             *     awaiting_client_last_week: int,
             *     weekly_throughput: list<array{week_start: string, documents_received: int}>
             * } $cached */
            return DashboardMetricsData::fromArray($cached);
        }

        // Unreachable in practice — flexible() returns the callback's value on a
        // miss — but the store is untyped, so recompute rather than guess.
        return $callback();
    }

    public static function forget(int $tenantId): void
    {
        Cache::forget(self::key($tenantId));
    }

    /**
     * Date in the key so overdue / weekly-trend metrics roll over at midnight.
     */
    private static function key(int $tenantId): string
    {
        return sprintf(
            'dashboard:metrics:%s:%d:%s',
            self::SCHEMA_VERSION,
            $tenantId,
            today()->toDateString(),
        );
    }
}
