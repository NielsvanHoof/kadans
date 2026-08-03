<?php

declare(strict_types=1);

namespace App\Reporting\Support;

use App\Reporting\Data\DashboardMetricsData;
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
 */
final class WorkspaceDashboardCache
{
    private const int TTL_SECONDS = 300;

    /**
     * @param  Closure(): DashboardMetricsData  $callback
     */
    public static function rememberMetrics(int $tenantId, Closure $callback): DashboardMetricsData
    {
        $cached = Cache::get(self::key($tenantId));

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
             *     awaiting_client_last_week: int
             * } $cached */
            return DashboardMetricsData::fromArray($cached);
        }

        $metrics = $callback();

        // Store a plain array so deploys that change the DTO shape stay safe.
        Cache::put(self::key($tenantId), $metrics->toArray(), self::TTL_SECONDS);

        return $metrics;
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
        return sprintf('dashboard:metrics:%d:%s', $tenantId, today()->toDateString());
    }
}
