<?php

declare(strict_types=1);

namespace App\Dossiers\Data;

use App\Dossiers\Enums\DossierStatus;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Lean dossier payload for the review queue page.
 */
#[TypeScript]
final class DossierReviewData extends Data
{
    /**
     * @param  list<StaffDocumentRequestData>  $documentRequests
     */
    public function __construct(
        public readonly int $id,
        public readonly string $title,
        public readonly DossierStatus $status,
        public readonly DossierClientSummaryData $client,
        public readonly array $documentRequests,
        public readonly DossierReviewSummaryData $reviewSummary,
        public readonly bool $readyToComplete,
    ) {}
}
