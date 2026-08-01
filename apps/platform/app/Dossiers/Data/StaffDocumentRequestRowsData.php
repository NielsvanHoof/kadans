<?php

declare(strict_types=1);

namespace App\Dossiers\Data;

/**
 * Mapped staff document-request rows plus progress counters shared by dossier pages.
 */
final readonly class StaffDocumentRequestRowsData
{
    /**
     * @param  list<StaffDocumentRequestData>  $rows
     */
    public function __construct(
        public array $rows,
        public DossierReviewSummaryData $reviewSummary,
        public bool $hasOutstandingClientItems,
        public bool $readyToComplete,
    ) {}
}
