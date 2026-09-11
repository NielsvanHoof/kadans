<?php

declare(strict_types=1);

namespace App\Reporting\Queries\Dashboard;

use App\Pipelines\Data\PipelineSubjectData;
use App\Pipelines\Enums\PipelineStatus;
use App\Pipelines\Models\Pipeline;
use App\Pipelines\Support\PipelineSubjectPresenterRegistry;
use App\Reporting\Data\Dashboard\DashboardPipelineFailureData;
use App\Reporting\Data\Dashboard\DashboardProcessingHealthData;
use App\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Str;

/**
 * OCR, transcription and export runs that stopped needing a machine and started
 * needing a person.
 *
 * Never memoised: Pipeline does not use InvalidatesTenantCaches, so a cached
 * count would keep reporting a failure after someone retried it. Adding the
 * trait instead would be worse — pipelines write several times per run, and
 * each write would evict the expensive aggregate metrics the cache exists for.
 */
final class FetchDashboardProcessingHealthQuery
{
    /** Rows listed under the counters. */
    private const int ROW_LIMIT = 5;

    /** Provider errors can run to kilobytes; the card shows a first line. */
    private const int ERROR_EXCERPT = 160;

    public function __construct(
        private readonly PipelineSubjectPresenterRegistry $subjectPresenters,
    ) {}

    public function handle(Tenant $tenant): DashboardProcessingHealthData
    {
        $failed = $this->countByStatus($tenant, PipelineStatus::Failed);
        $stuck = $this->countByStatus($tenant, PipelineStatus::Stuck);

        return new DashboardProcessingHealthData(
            failed: $failed,
            stuck: $stuck,
            recent: $failed + $stuck === 0 ? [] : $this->recentFailures($tenant),
        );
    }

    private function countByStatus(Tenant $tenant, PipelineStatus $status): int
    {
        return Pipeline::query()
            ->whereBelongsTo($tenant)
            ->where('status', $status)
            ->toBase()
            ->count();
    }

    /**
     * Newest first — matches the (tenant_id, status, created_at) index.
     *
     * @return list<DashboardPipelineFailureData>
     */
    private function recentFailures(Tenant $tenant): array
    {
        $query = Pipeline::query()
            ->whereBelongsTo($tenant)
            ->with([
                'pipelineable' => function (Relation $relation): void {
                    if ($relation instanceof MorphTo) {
                        $relation->morphWith($this->subjectPresenters->eagerLoads());
                    }
                },
            ])
            ->latest();

        $query->getQuery()
            ->whereIn('status', PipelineStatus::retryableValues())
            ->limit(self::ROW_LIMIT);

        /** @var list<DashboardPipelineFailureData> $rows */
        $rows = [];

        foreach ($query->get(['id', 'type', 'status', 'error_message', 'last_activity_at', 'pipelineable_type', 'pipelineable_id']) as $pipeline) {
            $rows[] = new DashboardPipelineFailureData(
                id: $pipeline->id,
                type: $pipeline->type,
                status: $pipeline->status,
                errorMessage: $pipeline->error_message === null
                    ? null
                    : Str::limit($pipeline->error_message, self::ERROR_EXCERPT),
                lastActivityAt: $pipeline->last_activity_at?->toIso8601String(),
                isRetryable: $pipeline->status->isRetryable(),
                subject: PipelineSubjectData::fromModel($pipeline, $this->subjectPresenters->for($pipeline->type)),
            );
        }

        return $rows;
    }
}
