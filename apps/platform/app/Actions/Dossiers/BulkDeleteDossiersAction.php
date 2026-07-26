<?php

declare(strict_types=1);

namespace App\Actions\Dossiers;

use App\Models\Dossier;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

final class BulkDeleteDossiersAction
{
    public function __construct(private readonly DeleteDossierAction $deleteDossier) {}

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
