<?php

declare(strict_types=1);

namespace App\Dossiers\Actions\Lifecycle;

use App\Accounts\Models\User;
use App\Dossiers\Models\Dossier;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

final readonly class BulkDeleteDossiersAction
{
    public function __construct(private DeleteDossierAction $deleteDossier) {}

    /**
     * Archive each selected dossier.
     *
     * @param  list<int>  $dossierIds
     */
    public function handle(array $dossierIds, User $deletedBy): int
    {
        $count = 0;

        foreach ($this->resolveDossiers($dossierIds) as $dossier) {
            Gate::forUser($deletedBy)->authorize('delete', $dossier);
            $this->deleteDossier->handle($dossier, $deletedBy);
            $count++;
        }

        return $count;
    }

    /**
     * Tenant scoping still applies; getQuery() only sidesteps the scoped
     * builder's whereIn() signature, and is kept here rather than in the
     * controller so the escape hatch lives next to the domain logic.
     *
     * @param  list<int>  $dossierIds
     * @return Collection<int, Dossier>
     */
    private function resolveDossiers(array $dossierIds): Collection
    {
        $dossiersQuery = Dossier::query()->with('tenant');
        $dossiersQuery->getQuery()->whereIn('id', $dossierIds);

        return $dossiersQuery->get();
    }
}
