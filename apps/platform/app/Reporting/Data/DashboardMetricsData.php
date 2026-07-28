<?php

declare(strict_types=1);

namespace App\Reporting\Data;

/**
 * Workspace dashboard metric counters.
 */
final readonly class DashboardMetricsData
{
    public function __construct(
        public int $clients,
        public int $openDossiers,
        public int $needsReview,
        public int $awaitingClient,
        public int $overdue,
        public int $inReview,
        public int $submittedDocumentRequests,
        public int $outstandingDocumentRequests,
        /** Dossiers created in the last 7 days — activity trend for prepare. */
        public int $openDossiersThisWeek = 0,
        /** Dossiers created in the prior 7 days (days 8–14). */
        public int $openDossiersLastWeek = 0,
        /** Document requests submitted in the last 7 days. */
        public int $needsReviewThisWeek = 0,
        public int $needsReviewLastWeek = 0,
        /** Outstanding document requests created in the last 7 days. */
        public int $awaitingClientThisWeek = 0,
        public int $awaitingClientLastWeek = 0,
    ) {}

    /**
     * @return array{
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
     * }
     */
    public function toArray(): array
    {
        return [
            'clients' => $this->clients,
            'open_dossiers' => $this->openDossiers,
            'needs_review' => $this->needsReview,
            'awaiting_client' => $this->awaitingClient,
            'overdue' => $this->overdue,
            'in_review' => $this->inReview,
            'submitted_document_requests' => $this->submittedDocumentRequests,
            'outstanding_document_requests' => $this->outstandingDocumentRequests,
            'open_dossiers_this_week' => $this->openDossiersThisWeek,
            'open_dossiers_last_week' => $this->openDossiersLastWeek,
            'needs_review_this_week' => $this->needsReviewThisWeek,
            'needs_review_last_week' => $this->needsReviewLastWeek,
            'awaiting_client_this_week' => $this->awaitingClientThisWeek,
            'awaiting_client_last_week' => $this->awaitingClientLastWeek,
        ];
    }
}
