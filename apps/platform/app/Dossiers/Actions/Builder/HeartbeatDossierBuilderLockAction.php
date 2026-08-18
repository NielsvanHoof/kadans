<?php

declare(strict_types=1);

namespace App\Dossiers\Actions\Builder;

use App\Accounts\Models\User;
use App\Dossiers\Data\DossierBuilderLockData;
use App\Dossiers\Models\Dossier;
use App\Dossiers\Support\DossierBuilderLock;
use App\Dossiers\Support\DossierLocks;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Extend the builder lock TTL for the holding session.
 */
final readonly class HeartbeatDossierBuilderLockAction
{
    public function __construct(
        private DossierLocks $dossierLocks,
    ) {}

    /**
     * @throws ConflictHttpException when the token does not match an active lock for this user
     */
    public function handle(Dossier $dossier, User $user, string $token): DossierBuilderLockData
    {
        return DB::transaction(function () use ($dossier, $user, $token): DossierBuilderLockData {
            $lockedDossier = $this->dossierLocks->dossier($dossier);
            $lockedDossier->loadMissing('builderLockedBy:id,name,email');

            if (
                ! $lockedDossier->isBuilderLockedBy($user)
                || $lockedDossier->builder_lock_token !== $token
            ) {
                throw new ConflictHttpException(__('This form is no longer locked by you.'));
            }

            $lockedDossier->forceFill([
                'builder_lock_expires_at' => now()->addSeconds(DossierBuilderLock::TTL_SECONDS),
            ])->save();

            return DossierBuilderLockData::fromDossier(
                $lockedDossier->fresh(['builderLockedBy:id,name,email']) ?? $lockedDossier,
                $user,
            );
        });
    }
}
