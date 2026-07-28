<?php

declare(strict_types=1);

namespace App\Dossiers\Policies;

use App\Accounts\Models\User;
use App\Dossiers\Enums\DossierStatus;
use App\Dossiers\Models\Dossier;
use App\Tenancy\Enums\Permission;
use App\Tenancy\Policies\Concerns\AuthorizesTenantPermission;

final class DossierPolicy
{
    use AuthorizesTenantPermission;

    public function viewAny(User $user): bool
    {
        return $this->userHasPermissionInCurrentTenant($user, Permission::ViewDossiers);
    }

    public function view(User $user, Dossier $dossier): bool
    {
        return $this->userHasPermissionInTenant(
            $user,
            $dossier->tenant,
            Permission::ViewDossiers,
        );
    }

    public function create(User $user): bool
    {
        return $this->userHasPermissionInCurrentTenant($user, Permission::CreateDossiers);
    }

    public function update(User $user, Dossier $dossier): bool
    {
        return $this->userHasPermissionInTenant($user, $dossier->tenant, Permission::CreateDossiers)
            && $dossier->status !== DossierStatus::Completed;
    }

    public function complete(User $user, Dossier $dossier): bool
    {
        return $this->userHasPermissionInTenant($user, $dossier->tenant, Permission::ReviewDocuments)
            && $dossier->status !== DossierStatus::Completed;
    }

    public function delete(User $user, Dossier $dossier): bool
    {
        return $this->userHasPermissionInTenant($user, $dossier->tenant, Permission::CreateDossiers);
    }

    public function restore(User $user, Dossier $dossier): bool
    {
        return $this->userHasPermissionInTenant($user, $dossier->tenant, Permission::CreateDossiers);
    }

    public function sendReminder(User $user, Dossier $dossier): bool
    {
        return $this->userHasPermissionInTenant($user, $dossier->tenant, Permission::CreateDossiers)
            && $dossier->status !== DossierStatus::Completed;
    }

    public function export(User $user, Dossier $dossier): bool
    {
        return $this->userHasPermissionInTenant(
            $user,
            $dossier->tenant,
            Permission::DownloadDocuments,
        );
    }
}
