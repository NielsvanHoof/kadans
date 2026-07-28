<?php

declare(strict_types=1);

namespace App\Dossiers\Queries;

use App\Dossiers\Enums\DocumentRequestStatus;
use App\Dossiers\Models\DocumentRequest;
use App\Dossiers\Models\Dossier;

/**
 * True when the dossier still has questionnaire items awaiting client input.
 */
final class DossierHasOutstandingClientItemsQuery
{
    public function handle(Dossier $dossier): bool
    {
        $query = DocumentRequest::query()->whereBelongsTo($dossier);

        // Use the base query builder for whereIn to satisfy Larastan.
        $query->getQuery()->whereIn('status', [
            DocumentRequestStatus::Pending->value,
            DocumentRequestStatus::Rejected->value,
        ]);

        return $query->toBase()->exists();
    }
}
