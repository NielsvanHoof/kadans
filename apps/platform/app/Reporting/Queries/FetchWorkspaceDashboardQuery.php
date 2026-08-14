<?php

declare(strict_types=1);

namespace App\Reporting\Queries;

use App\Clients\Models\Client;
use App\Dossiers\Enums\DocumentRequestStatus;
use App\Dossiers\Enums\DossierStatus;
use App\Dossiers\Models\DocumentRequest;
use App\Dossiers\Models\Dossier;
use App\Reporting\Data\DashboardMetricsData;
use App\Reporting\Data\DashboardRecentDossierData;
use App\Reporting\Data\WorkspaceDashboardPageData;
use App\Reporting\Support\WorkspaceDashboardCache;
use App\Tenancy\Models\Tenant;
use Illuminate\Contracts\Database\Query\Builder;
use RuntimeException;

final class FetchWorkspaceDashboardQuery
{
    public function handle(Tenant $tenant): WorkspaceDashboardPageData
    {
        // Metrics are tenant-scoped and expensive (~14 aggregate queries).
        // Recent dossiers stay live — one cheap query and expected to be fresh.
        return new WorkspaceDashboardPageData(
            metrics: WorkspaceDashboardCache::rememberMetrics(
                $tenant->id,
                fn (): DashboardMetricsData => $this->getMetrics($tenant),
            ),
            recentDossiers: $this->getRecentDossiers($tenant),
        );
    }

    public function getMetrics(Tenant $tenant): DashboardMetricsData
    {
        $outstandingDocumentRequestsQuery = DocumentRequest::query()
            ->whereBelongsTo($tenant);

        $outstandingDocumentRequestsQuery->getQuery()->whereIn(
            'status',
            DocumentRequestStatus::awaitingClientValues(),
        );

        $submittedDocumentRequests = DocumentRequest::query()
            ->whereBelongsTo($tenant)
            ->where('status', DocumentRequestStatus::Submitted)
            ->toBase()
            ->count();

        $awaitingClientQuery = Dossier::query()
            ->whereBelongsTo($tenant)
            ->whereHas('documentRequests', function (Builder $documentRequestQuery): void {
                $documentRequestQuery->getQuery()->whereIn(
                    'status',
                    DocumentRequestStatus::awaitingClientValues(),
                );
            });
        $awaitingClientQuery->getQuery()->whereNotIn('status', DossierStatus::lockedValues());

        $needsReviewQuery = Dossier::query()
            ->whereBelongsTo($tenant)
            ->whereHas('documentRequests', fn (Builder $documentRequestQuery) => $documentRequestQuery
                ->getQuery()
                ->where('status', DocumentRequestStatus::Submitted->value));
        $needsReviewQuery->getQuery()->whereNotIn('status', DossierStatus::lockedValues());
        $overdueQuery = clone $awaitingClientQuery;
        $overdueQuery->getQuery()
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<', today());

        $thisWeekStart = now()->subDays(7);
        $lastWeekStart = now()->subDays(14);

        // Use base query whereIn to avoid Eloquent Builder staticMethod.dynamicCall.
        $awaitingClientThisWeekQuery = DocumentRequest::query()
            ->whereBelongsTo($tenant)
            ->where('created_at', '>=', $thisWeekStart);
        $awaitingClientThisWeekQuery->getQuery()->whereIn(
            'status',
            DocumentRequestStatus::awaitingClientValues(),
        );

        $awaitingClientLastWeekQuery = DocumentRequest::query()
            ->whereBelongsTo($tenant)
            ->where('created_at', '>=', $lastWeekStart)
            ->where('created_at', '<', $thisWeekStart);
        $awaitingClientLastWeekQuery->getQuery()->whereIn(
            'status',
            DocumentRequestStatus::awaitingClientValues(),
        );

        $openDossiersQuery = Dossier::query()->whereBelongsTo($tenant);
        $openDossiersQuery->getQuery()->whereNotIn('status', DossierStatus::lockedValues());

        return new DashboardMetricsData(
            clients: Client::query()
                ->whereBelongsTo($tenant)
                ->toBase()
                ->count(),
            openDossiers: $openDossiersQuery->toBase()->count(),
            needsReview: $needsReviewQuery->toBase()->count(),
            awaitingClient: (clone $awaitingClientQuery)->toBase()->count(),
            overdue: $overdueQuery->toBase()->count(),
            inReview: Dossier::query()
                ->whereBelongsTo($tenant)
                ->where('status', DossierStatus::InReview)
                ->toBase()
                ->count(),
            submittedDocumentRequests: $submittedDocumentRequests,
            outstandingDocumentRequests: $outstandingDocumentRequestsQuery
                ->toBase()
                ->count(),
            openDossiersThisWeek: Dossier::query()
                ->whereBelongsTo($tenant)
                ->where('created_at', '>=', $thisWeekStart)
                ->toBase()
                ->count(),
            openDossiersLastWeek: Dossier::query()
                ->whereBelongsTo($tenant)
                ->where('created_at', '>=', $lastWeekStart)
                ->where('created_at', '<', $thisWeekStart)
                ->toBase()
                ->count(),
            needsReviewThisWeek: DocumentRequest::query()
                ->whereBelongsTo($tenant)
                ->where('status', DocumentRequestStatus::Submitted)
                ->where('updated_at', '>=', $thisWeekStart)
                ->toBase()
                ->count(),
            needsReviewLastWeek: DocumentRequest::query()
                ->whereBelongsTo($tenant)
                ->where('status', DocumentRequestStatus::Submitted)
                ->where('updated_at', '>=', $lastWeekStart)
                ->where('updated_at', '<', $thisWeekStart)
                ->toBase()
                ->count(),
            awaitingClientThisWeek: $awaitingClientThisWeekQuery->toBase()->count(),
            awaitingClientLastWeek: $awaitingClientLastWeekQuery->toBase()->count(),
        );
    }

    /**
     * @return list<DashboardRecentDossierData>
     */
    public function getRecentDossiers(Tenant $tenant): array
    {
        $recentDossiersQuery = Dossier::query()
            ->whereBelongsTo($tenant)
            ->with(['client:id,name', 'responsibleUser:id,name'])
            ->latest();

        $recentDossiersQuery->getQuery()->limit(5);

        /** @var list<DashboardRecentDossierData> $rows */
        $rows = [];

        foreach ($recentDossiersQuery->get([
            'id',
            'client_id',
            'responsible_user_id',
            'title',
            'reference',
            'status',
            'due_date',
            'updated_at',
        ]) as $dossier) {
            $client = $dossier->client;

            throw_unless($client instanceof Client, RuntimeException::class, 'Dossier client is missing.');

            $rows[] = new DashboardRecentDossierData(
                id: $dossier->id,
                title: $dossier->title,
                reference: $dossier->reference,
                status: $dossier->status,
                clientName: $client->name,
                dueDate: $dossier->due_date?->toDateString(),
                responsibleName: $dossier->responsibleUser?->name,
                updatedAt: $dossier->updated_at?->toIso8601String() ?? '',
            );
        }

        return $rows;
    }
}
