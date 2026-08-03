<?php

declare(strict_types=1);

namespace App\Reporting\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Full payload for the workspace dashboard Inertia page.
 */
#[TypeScript]
final class WorkspaceDashboardPageData extends Data
{
    /**
     * @param  list<DashboardRecentDossierData>  $recentDossiers
     */
    public function __construct(
        public readonly DashboardMetricsData $metrics,
        public readonly array $recentDossiers,
    ) {}
}
