<?php

declare(strict_types=1);

namespace App\Dossiers\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Mapped staff document-request rows plus progress counters shared by dossier pages.
 */
#[TypeScript]
final class StaffDocumentRequestRowsData extends Data
{
    /**
     * @param  list<StaffDocumentRequestData>  $rows
     */
    public function __construct(
        public readonly array $rows,
        public readonly DossierReviewSummaryData $reviewSummary,
        public readonly bool $hasOutstandingClientItems,
        public readonly bool $readyToComplete,
    ) {}
}
