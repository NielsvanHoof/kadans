<?php

declare(strict_types=1);

namespace App\Dossiers\Http\Requests\Builder\Concerns;

use App\Dossiers\Models\DocumentRequest;
use App\Dossiers\Models\Dossier;

/**
 * Shared authorize gate for builder structure mutations.
 */
trait AuthorizesDossierBuilderMutation
{
    /**
     * Must be able to update the dossier, create document requests, and hold the lock.
     */
    protected function userHoldsBuilderLockForMutation(): bool
    {
        $dossier = $this->route('dossier');

        if (! $dossier instanceof Dossier) {
            return false;
        }

        $user = $this->user();

        return $user !== null
            && $user->can('update', $dossier)
            && $user->can('create', DocumentRequest::class)
            && $dossier->isBuilderLockedBy($user);
    }

    /**
     * Can attempt to acquire / heartbeat / release (permission only — lock checked in action).
     */
    protected function userCanManageBuilderLock(): bool
    {
        $dossier = $this->route('dossier');

        if (! $dossier instanceof Dossier) {
            return false;
        }

        $user = $this->user();

        return $user !== null
            && $user->can('update', $dossier)
            && $user->can('create', DocumentRequest::class);
    }
}
