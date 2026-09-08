<?php

declare(strict_types=1);

namespace App\Dossiers\Queries;

use App\Dossiers\Actions\DocumentRequests\ResolveApplicableDocumentRequestsAction;
use App\Dossiers\Models\DocumentRequest;
use App\Dossiers\Models\Dossier;

/**
 * True when the dossier still has questionnaire items awaiting client input.
 *
 * Hidden and unanswered optional fields are excluded.
 */
final readonly class DossierHasOutstandingClientItemsQuery
{
    public function __construct(
        private ResolveApplicableDocumentRequestsAction $resolveApplicableDocumentRequests,
    ) {}

    public function handle(Dossier $dossier): bool
    {
        $documentRequests = DocumentRequest::query()
            ->whereBelongsTo($dossier)
            ->oldest('sort_order')
            ->oldest('id')
            ->get();

        return $this->resolveApplicableDocumentRequests
            ->handle($documentRequests)
            ->contains(
                fn (DocumentRequest $documentRequest): bool => $documentRequest->status->isAwaitingClient(),
            );
    }
}
