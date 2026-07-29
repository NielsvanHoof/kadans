<?php

declare(strict_types=1);

namespace App\Dossiers\Actions\DocumentRequests;

use App\Dossiers\Enums\DocumentRequestStatus;
use App\Dossiers\Models\DocumentRequest;
use Illuminate\Support\Collection;

/**
 * Fields that count toward portal progress, review, and dossier completion.
 *
 * Hidden (conditional) fields are not applicable.
 * Optional fields that were never answered are also skipped.
 */
final readonly class ResolveApplicableDocumentRequestsAction
{
    public function __construct(
        private DetermineDocumentRequestVisibilityAction $determineVisibility,
    ) {}

    /**
     * @param  Collection<int, DocumentRequest>  $documentRequests
     * @return Collection<int, DocumentRequest>
     */
    public function handle(Collection $documentRequests): Collection
    {
        return $documentRequests
            ->filter(function (DocumentRequest $documentRequest) use ($documentRequests): bool {
                if (! $this->determineVisibility->handle($documentRequest, $documentRequests)) {
                    return false;
                }

                $isRequired = ($documentRequest->configuration['is_required'] ?? true) !== false;
                // Optional unanswered fields do not block progress or completion.
                return $isRequired || $documentRequest->status !== DocumentRequestStatus::Pending;
            })
            ->values();
    }
}
