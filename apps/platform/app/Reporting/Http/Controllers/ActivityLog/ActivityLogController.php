<?php

declare(strict_types=1);

namespace App\Reporting\Http\Controllers\ActivityLog;

use App\Audit\Models\Activity;
use App\Reporting\Queries\ActivityLog\FetchActivityLogIndexQuery;
use App\Shared\Http\Controllers\Controller;
use App\Tenancy\Models\Tenant;
use Inertia\Inertia;
use Inertia\Response;

final class ActivityLogController extends Controller
{
    /**
     * Tenant-scoped audit activity feed (latest entries).
     */
    public function index(
        Tenant $tenant,
        FetchActivityLogIndexQuery $fetchActivityLogIndex,
    ): Response {
        $this->authorize('viewAny', Activity::class);

        return Inertia::render('workspaces/activity/index', [
            'activities' => $fetchActivityLogIndex->handle($tenant),
            ...$fetchActivityLogIndex->indexQueryProps(),
        ]);
    }
}
