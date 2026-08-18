<?php

declare(strict_types=1);

namespace App\Dossiers\Policies;

use App\Accounts\Models\User;
use App\Dossiers\Enums\DossierPermission;
use App\Dossiers\Models\Dossier;
use App\Tenancy\Policies\Concerns\AuthorizesTenantPermission;

final class DossierPolicy
{
    use AuthorizesTenantPermission;

    public function viewAny(User $user): bool
    {
        return $this->userHasPermissionInCurrentTenant($user, DossierPermission::View);
    }

    public function view(User $user, Dossier $dossier): bool
    {
        return $this->userHasPermissionInTenant(
            $user,
            $dossier->tenant,
            DossierPermission::View,
        );
    }

    public function create(User $user): bool
    {
        return $this->userHasPermissionInCurrentTenant($user, DossierPermission::Create);
    }

    public function update(User $user, Dossier $dossier): bool
    {
        return $this->userHasPermissionInTenant($user, $dossier->tenant, DossierPermission::Update)
            && ! $dossier->status->isLocked();
    }

    public function complete(User $user, Dossier $dossier): bool
    {
        return $this->userHasPermissionInTenant($user, $dossier->tenant, DossierPermission::Complete)
            && ! $dossier->status->isLocked();
    }

    public function delete(User $user, Dossier $dossier): bool
    {
        return $this->userHasPermissionInTenant($user, $dossier->tenant, DossierPermission::Delete);
    }

    /**
     * Class-level gate for bulk archiving, where there is no single dossier yet.
     */
    public function deleteAny(User $user): bool
    {
        return $this->userHasPermissionInCurrentTenant($user, DossierPermission::Delete);
    }

    public function restore(User $user, Dossier $dossier): bool
    {
        return $this->userHasPermissionInTenant($user, $dossier->tenant, DossierPermission::Restore);
    }

    /**
     * Class-level gate for the recycle bin and bulk restore.
     */
    public function restoreAny(User $user): bool
    {
        return $this->userHasPermissionInCurrentTenant($user, DossierPermission::Restore);
    }

    public function sendReminder(User $user, Dossier $dossier): bool
    {
        return $this->userHasPermissionInTenant($user, $dossier->tenant, DossierPermission::SendReminder)
            && ! $dossier->status->isLocked();
    }

    public function export(User $user, Dossier $dossier): bool
    {
        return $this->userHasPermissionInTenant(
            $user,
            $dossier->tenant,
            DossierPermission::Export,
        );
    }
}
