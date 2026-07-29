<?php

declare(strict_types=1);

namespace App\Dossiers\Policies;

use App\Accounts\Models\User;
use App\Dossiers\Enums\UploadedDocumentPermission;
use App\Dossiers\Models\UploadedDocument;
use App\Tenancy\Policies\Concerns\AuthorizesTenantPermission;

final class UploadedDocumentPolicy
{
    use AuthorizesTenantPermission;

    /**
     * Opening the OCR extraction page for a document.
     */
    public function view(User $user, UploadedDocument $uploadedDocument): bool
    {
        return $this->userHasPermissionInTenant(
            $user,
            $uploadedDocument->tenant,
            UploadedDocumentPermission::View,
        );
    }

    public function download(User $user, UploadedDocument $uploadedDocument): bool
    {
        return $this->userHasPermissionInTenant(
            $user,
            $uploadedDocument->tenant,
            UploadedDocumentPermission::Download,
        );
    }
}
