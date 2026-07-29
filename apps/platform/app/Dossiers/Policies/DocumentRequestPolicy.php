<?php

declare(strict_types=1);

namespace App\Dossiers\Policies;

use App\Accounts\Models\User;
use App\Dossiers\Enums\DocumentRequestPermission;
use App\Dossiers\Enums\DossierStatus;
use App\Dossiers\Models\DocumentRequest;
use App\Dossiers\Models\Dossier;
use App\Tenancy\Contracts\TenantPermission;
use App\Tenancy\Policies\Concerns\AuthorizesTenantPermission;

final class DocumentRequestPolicy
{
    use AuthorizesTenantPermission;

    /**
     * Media library and any future document-request index.
     */
    public function viewAny(User $user): bool
    {
        return $this->userHasPermissionInCurrentTenant($user, DocumentRequestPermission::View);
    }

    public function create(User $user): bool
    {
        return $this->userHasPermissionInCurrentTenant($user, DocumentRequestPermission::Create);
    }

    public function view(User $user, DocumentRequest $documentRequest): bool
    {
        return $this->userHasPermissionInTenant(
            $user,
            $documentRequest->tenant,
            DocumentRequestPermission::View,
        );
    }

    /**
     * Staff fallback upload onto a request (e.g. files received by email or walk-in).
     * Clients remain the primary upload path via the portal.
     */
    public function upload(User $user, DocumentRequest $documentRequest): bool
    {
        return $this->canActOnOpenDossier($user, $documentRequest, DocumentRequestPermission::Upload);
    }

    public function update(User $user, DocumentRequest $documentRequest): bool
    {
        return $this->canActOnOpenDossier($user, $documentRequest, DocumentRequestPermission::Update);
    }

    public function delete(User $user, DocumentRequest $documentRequest): bool
    {
        return $this->canActOnOpenDossier($user, $documentRequest, DocumentRequestPermission::Delete);
    }

    public function review(User $user, DocumentRequest $documentRequest): bool
    {
        return $this->canActOnOpenDossier($user, $documentRequest, DocumentRequestPermission::Review);
    }

    private function canActOnOpenDossier(
        User $user,
        DocumentRequest $documentRequest,
        TenantPermission $permission,
    ): bool {
        $dossier = $documentRequest->dossier;

        return $this->userHasPermissionInTenant($user, $documentRequest->tenant, $permission)
            && $dossier instanceof Dossier
            && $dossier->status !== DossierStatus::Completed;
    }
}
