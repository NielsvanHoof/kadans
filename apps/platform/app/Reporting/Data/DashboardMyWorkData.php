<?php

declare(strict_types=1);

namespace App\Reporting\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The signed-in user's own slice of the workspace.
 *
 * Deliberately not part of DashboardMetricsData: that payload is memoised under
 * a tenant-wide cache key, so per-user counters stored there would be served to
 * every other member of the firm for the rest of the TTL.
 *
 * @see \App\Reporting\Support\WorkspaceDashboardCache
 */
#[TypeScript]
final class DashboardMyWorkData extends Data
{
    /**
     * @param  list<DashboardAgendaItemData>  $agenda
     */
    public function __construct(
        /** Open dossiers where this user is the responsible staff member. */
        public readonly int $assignedOpen,
        /** Of those, the ones due within the next seven days. */
        public readonly int $dueThisWeek,
        /** Of those, the ones whose due date has already passed. */
        public readonly int $overdue,
        /** Unreviewed submissions sitting on this user's dossiers. */
        public readonly int $awaitingMyReview,
        public readonly array $agenda,
    ) {}
}
