<?php

declare(strict_types=1);

namespace App\Actions\Dossiers;

use App\Models\Dossier;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

final class BulkRestoreDossiersAction
{
    public function __construct(private readonly RestoreDossierAction $restoreDossier) {}

    /**
     * Restore each selected archived dossier (client must already be active).
     *
     * @param  Collection<int, Dossier>  $dossiers
     */
    public function handle(Collection $dossiers, User $restoredBy): int
    {
        $count = 0;

        foreach ($dossiers as $dossier) {
            Gate::forUser($restoredBy)->authorize('restore', $dossier);
            $this->restoreDossier->handle($dossier, $restoredBy);
            $count++;
        }

        return $count;
    }
}
