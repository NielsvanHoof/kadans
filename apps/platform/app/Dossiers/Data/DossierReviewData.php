<?php

declare(strict_types=1);

namespace App\Dossiers\Data;

/**
 * Lean dossier payload for the review queue page.
 */
final readonly class DossierReviewData
{
    /**
     * @param  list<StaffDocumentRequestData>  $documentRequests
     */
    public function __construct(
        public int $id,
        public string $title,
        public string $status,
        public DossierClientSummaryData $client,
        public array $documentRequests,
        public DossierReviewSummaryData $reviewSummary,
        public bool $readyToComplete,
    ) {}

    /**
     * @return array{
     *     id: int,
     *     title: string,
     *     status: string,
     *     client: array{name: string, email: string},
     *     document_requests: list<array<string, mixed>>,
     *     review_summary: array{
     *         total: int,
     *         pending: int,
     *         submitted: int,
     *         accepted: int,
     *         rejected: int
     *     },
     *     ready_to_complete: bool
     * }
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'status' => $this->status,
            'client' => $this->client->toArray(),
            'document_requests' => array_map(
                static fn (StaffDocumentRequestData $request): array => $request->toArray(),
                $this->documentRequests,
            ),
            'review_summary' => $this->reviewSummary->toArray(),
            'ready_to_complete' => $this->readyToComplete,
        ];
    }
}
