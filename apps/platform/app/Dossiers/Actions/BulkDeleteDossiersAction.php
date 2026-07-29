<?php

declare(strict_types=1);

namespace App\Dossiers\Actions;

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
     * @param  Collection<int, Dossier>  $dossiers
     */
    public function handle(Collection $dossiers, User $deletedBy): int
    {
        $count = 0;

        foreach ($dossiers as $dossier) {
            Gate::forUser($deletedBy)->authorize('delete', $dossier);
            $this->deleteDossier->handle($dossier, $deletedBy);
            $count++;
        }

        return $count;
    }
}
