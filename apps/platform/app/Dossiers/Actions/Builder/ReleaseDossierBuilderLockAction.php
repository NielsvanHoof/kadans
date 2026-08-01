<?php

declare(strict_types=1);

namespace App\Dossiers\Actions\Builder;

use App\Accounts\Models\User;
use App\Dossiers\Data\DossierBuilderLockData;
use App\Dossiers\Models\Dossier;
use Illuminate\Support\Facades\DB;

/**
 * Release the builder lock when the holder leaves (or tab closes).
 */
final readonly class ReleaseDossierBuilderLockAction
{
    /**
     * Only clears when the token still matches the holder's lease.
     * Safe no-op when already expired or stolen by timeout + re-acquire.
     */
    public function handle(Dossier $dossier, User $user, string $token): DossierBuilderLockData
    {
        return DB::transaction(function () use ($dossier, $user, $token): DossierBuilderLockData {
            $dossierQuery = Dossier::query()->whereKey($dossier->getKey());
            $dossierQuery->getQuery()->lockForUpdate();
            $lockedDossier = $dossierQuery->firstOrFail();

            if (
                (int) $lockedDossier->builder_locked_by_user_id === (int) $user->getKey()
                && $lockedDossier->builder_lock_token === $token
            ) {
                $lockedDossier->clearBuilderLock();
            }

            $fresh = $lockedDossier->fresh(['builderLockedBy:id,name,email']) ?? $lockedDossier;

            return DossierBuilderLockData::fromDossier($fresh, $user);
        });
    }
}
