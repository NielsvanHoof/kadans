<?php

declare(strict_types=1);

namespace App\Reporting\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Workspace dashboard metric counters.
 */
#[TypeScript]
final class DashboardMetricsData extends Data
{
    /**
     * @param  list<DashboardTrendPointData>  $weeklyThroughput
     */
    public function __construct(
        public readonly int $clients,
        public readonly int $openDossiers,
        public readonly int $needsReview,
        public readonly int $awaitingClient,
        public readonly int $overdue,
        public readonly int $inReview,
        public readonly int $submittedDocumentRequests,
        public readonly int $outstandingDocumentRequests,
        /** Dossiers created in the last 7 days — activity trend for prepare. */
        public readonly int $openDossiersThisWeek = 0,
        /** Dossiers created in the prior 7 days (days 8–14). */
        public readonly int $openDossiersLastWeek = 0,
        /** Document requests submitted in the last 7 days. */
        public readonly int $needsReviewThisWeek = 0,
        public readonly int $needsReviewLastWeek = 0,
        /** Outstanding document requests created in the last 7 days. */
        public readonly int $awaitingClientThisWeek = 0,
        public readonly int $awaitingClientLastWeek = 0,
        /** Eight weekly buckets of documents received, oldest first. */
        public readonly array $weeklyThroughput = [],
    ) {}

    /**
     * Rebuild from the array shape stored in cache (mirrors toArray()).
     *
     * @param  array{
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
     * }  $metrics
     */
    public static function fromArray(array $metrics): self
    {
        /** @var list<DashboardTrendPointData> $weeklyThroughput */
        $weeklyThroughput = [];

        foreach ($metrics['weekly_throughput'] as $point) {
            $weeklyThroughput[] = new DashboardTrendPointData(
                weekStart: $point['week_start'],
                documentsReceived: $point['documents_received'],
            );
        }

        return new self(
            clients: $metrics['clients'],
            openDossiers: $metrics['open_dossiers'],
            needsReview: $metrics['needs_review'],
            awaitingClient: $metrics['awaiting_client'],
            overdue: $metrics['overdue'],
            inReview: $metrics['in_review'],
            submittedDocumentRequests: $metrics['submitted_document_requests'],
            outstandingDocumentRequests: $metrics['outstanding_document_requests'],
            openDossiersThisWeek: $metrics['open_dossiers_this_week'],
            openDossiersLastWeek: $metrics['open_dossiers_last_week'],
            needsReviewThisWeek: $metrics['needs_review_this_week'],
            needsReviewLastWeek: $metrics['needs_review_last_week'],
            awaitingClientThisWeek: $metrics['awaiting_client_this_week'],
            awaitingClientLastWeek: $metrics['awaiting_client_last_week'],
            weeklyThroughput: $weeklyThroughput,
        );
    }
}
