<?php

declare(strict_types=1);

namespace App\Dossiers\Policies;

use App\Accounts\Models\User;
use App\Dossiers\Enums\DocumentRequestPermission;
use App\Dossiers\Enums\SubmissionContext;
use App\Dossiers\Enums\UploadedDocumentPermission;
use App\Dossiers\Models\UploadedDocument;
use App\Dossiers\Transitions\DocumentRequestTransitions;
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
     * Removing a file from a document request.
     *
     * Gated the same way staff fallback uploads are: it's the same "acting on
     * behalf of a client on an open dossier" capability. Eligibility itself
     * lives in DocumentRequestTransitions::canDeleteUpload() so staff and the
     * portal read one rule — see its docblock for why the two contexts
     * deliberately differ.
     */
    public function delete(User $user, UploadedDocument $uploadedDocument): bool
    {
        $documentRequest = $uploadedDocument->documentRequest;

        return $this->userHasPermissionInTenant($user, $uploadedDocument->tenant, DocumentRequestPermission::Upload)
            && resolve(DocumentRequestTransitions::class)->canDeleteUpload(
                $documentRequest,
                SubmissionContext::Staff,
                $documentRequest->dossier,
            );
    }
}
