<?php

declare(strict_types=1);

namespace App\Dossiers\Policies;

use App\Accounts\Models\User;
use App\Dossiers\Enums\DocumentRequestPermission;
use App\Dossiers\Enums\UploadedDocumentPermission;
use App\Dossiers\Models\Dossier;
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

    /**
     * Removing one of several files from a multi-file document request.
     * Gated the same way staff fallback uploads are: it's the same "acting on
     * behalf of a client on an open dossier" capability.
     */
    public function delete(User $user, UploadedDocument $uploadedDocument): bool
    {
        $dossier = $uploadedDocument->documentRequest->dossier;

        return $dossier instanceof Dossier
            && $this->userHasPermissionInTenant($user, $uploadedDocument->tenant, DocumentRequestPermission::Upload)
            && ! $dossier->status->isLocked();
    }
}
