<?php

declare(strict_types=1);

namespace App\Dossiers\Policies;

use App\Accounts\Models\User;
use App\Dossiers\Models\UploadedDocument;
use App\Tenancy\Enums\Permission;
use App\Tenancy\Policies\Concerns\AuthorizesTenantPermission;

final class UploadedDocumentPolicy
{
    use AuthorizesTenantPermission;

    /**
     * Staff can open the OCR extraction page when they can view dossiers.
     */
    public function view(User $user, UploadedDocument $uploadedDocument): bool
    {
        return $this->userHasPermissionInTenant(
            $user,
            $uploadedDocument->tenant,
            Permission::ViewDossiers,
        );
    }

    public function download(User $user, UploadedDocument $uploadedDocument): bool
    {
        return $this->userHasPermissionInTenant(
            $user,
            $uploadedDocument->tenant,
            Permission::DownloadDocuments,
        );
    }
}
