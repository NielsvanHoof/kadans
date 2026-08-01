<?php

declare(strict_types=1);

namespace App\Dossiers\Actions;

use App\Accounts\Models\User;
use App\Dossiers\Models\Dossier;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

final readonly class BulkRestoreDossiersAction
{
    public function __construct(private RestoreDossierAction $restoreDossier) {}

    /**
     * Restore each selected archived dossier (client must already be active).
     *
     * @param  list<int>  $dossierIds
     */
    public function handle(array $dossierIds, User $restoredBy): int
    {
        $count = 0;

        foreach ($this->resolveTrashedDossiers($dossierIds) as $dossier) {
            Gate::forUser($restoredBy)->authorize('restore', $dossier);
            $this->restoreDossier->handle($dossier, $restoredBy);
            $count++;
        }

        return $count;
    }

    /**
     * Tenant scoping still applies; getQuery() only sidesteps the scoped
     * builder's whereIn() signature.
     *
     * @param  list<int>  $dossierIds
     * @return Collection<int, Dossier>
     */
    private function resolveTrashedDossiers(array $dossierIds): Collection
    {
        $dossiersQuery = Dossier::onlyTrashed()->with('tenant');
        $dossiersQuery->getQuery()->whereIn('id', $dossierIds);

        return $dossiersQuery->get();
    }
}
