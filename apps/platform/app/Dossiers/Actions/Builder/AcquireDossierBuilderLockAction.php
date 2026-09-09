<?php

declare(strict_types=1);

namespace App\Dossiers\Actions\Builder;

use App\Accounts\Models\User;
use App\Dossiers\Data\Builder\DossierBuilderLockData;
use App\Dossiers\Models\Dossier;
use App\Dossiers\Support\Builder\DossierBuilderLock;
use App\Dossiers\Support\DossierLocks;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Take or refresh the exclusive builder edit lease for a dossier.
 */
final readonly class AcquireDossierBuilderLockAction
{
    public function __construct(
        private DossierLocks $dossierLocks,
    ) {}

    /**
     * Acquire when free / expired / already ours. Otherwise return the existing holder.
     */
    public function handle(Dossier $dossier, User $user): DossierBuilderLockData
    {
        return DB::transaction(function () use ($dossier, $user): DossierBuilderLockData {
            $lockedDossier = $this->dossierLocks->dossier($dossier);
            $lockedDossier->loadMissing('builderLockedBy:id,name,email');

            // Same user already holds it — extend TTL and reuse the token.
            if ($lockedDossier->isBuilderLockedBy($user)) {
                $lockedDossier->forceFill([
                    'builder_lock_expires_at' => now()->addSeconds(DossierBuilderLock::TTL_SECONDS),
                ])->save();

                return DossierBuilderLockData::fromDossier($lockedDossier->fresh(['builderLockedBy:id,name,email']) ?? $lockedDossier, $user);
            }

            // Someone else still holds an active lock — read-only for this user.
            if ($lockedDossier->hasActiveBuilderLock()) {
                return DossierBuilderLockData::fromDossier($lockedDossier, $user);
            }

            // Free or expired — claim it.
            $lockedDossier->forceFill([
                'builder_locked_by_user_id' => $user->getKey(),
                'builder_lock_token' => (string) Str::ulid(),
                'builder_lock_expires_at' => now()->addSeconds(DossierBuilderLock::TTL_SECONDS),
            ])->save();

            return DossierBuilderLockData::fromDossier(
                $lockedDossier->fresh(['builderLockedBy:id,name,email']) ?? $lockedDossier,
                $user,
            );
        });
    }
}
