<?php

declare(strict_types=1);

namespace App\Reporting\Queries\Dashboard;

use App\Dossiers\Models\DocumentRequest;
use App\Reporting\Data\Dashboard\DashboardTrendPointData;
use App\Tenancy\Models\Tenant;

/**
 * Weekly client throughput for the dashboard trend chart.
 *
 * Buckets in PHP rather than SQL: Postgres wants date_trunc and SQLite wants
 * strftime, and this project runs Postgres in production but SQLite in tests.
 * Selecting one bounded column and grouping in memory is identical on both, and
 * the result is memoised with the rest of the dashboard metrics anyway.
 */
final class FetchDashboardTrendQuery
{
    private const int WEEKS = 8;

    /**
     * @return list<DashboardTrendPointData>
     */
    public function handle(Tenant $tenant): array
    {
        $firstWeekStart = today()->startOfWeek()->subWeeks(self::WEEKS - 1);

        $query = DocumentRequest::query()->whereBelongsTo($tenant);

        $query->getQuery()
            ->whereNotNull('answered_at')
            ->where('answered_at', '>=', $firstWeekStart);

        /** @var array<string, int> $buckets */
        $buckets = [];

        // Seed every week so a quiet week is a zero column, not a gap. A missing
        // bucket on a date axis reads as "no data", which is a different claim.
        foreach (range(0, self::WEEKS - 1) as $offset) {
            $buckets[$firstWeekStart->copy()->addWeeks($offset)->toDateString()] = 0;
        }

        foreach ($query->pluck('answered_at') as $answeredAt) {
            if ($answeredAt === null) {
                continue;
            }

            $weekStart = $answeredAt->startOfWeek()->toDateString();

            if (isset($buckets[$weekStart])) {
                $buckets[$weekStart]++;
            }
        }

        /** @var list<DashboardTrendPointData> $points */
        $points = [];

        foreach ($buckets as $weekStart => $documentsReceived) {
            $points[] = new DashboardTrendPointData(
                weekStart: $weekStart,
                documentsReceived: $documentsReceived,
            );
        }

        return $points;
    }
}
