<?php

declare(strict_types=1);

namespace App\Dossiers\Actions\DocumentRequests;

use App\Audit\Actions\LogAuditActivityAction;
use App\Audit\Enums\AuditEvent;
use App\ClientPortal\Enums\SubmissionContext;
use App\Dossiers\Models\DocumentRequest;
use App\Dossiers\Models\Dossier;
use App\Dossiers\Models\UploadedDocument;
use App\Dossiers\Transitions\DossierTransitions;
use Illuminate\Http\UploadedFile;

/**
 * Staff upload: store the file, mark the dossier in review, and audit.
 */
final class UploadStaffDocumentAction
{
    public function __construct(
        private readonly UploadDocumentForRequestAction $uploadDocumentForRequest,
        private readonly LogAuditActivityAction $logAuditActivity,
        private readonly DossierTransitions $dossierTransitions,
    ) {}

    public function handle(
        DocumentRequest $documentRequest,
        UploadedFile $file,
    ): UploadedDocument {
        return $this->uploadDocumentForRequest->handle(
            $documentRequest,
            $file,
            function (
                UploadedDocument $uploadedDocument,
                DocumentRequest $lockedDocumentRequest,
            ): void {
                $dossierQuery = Dossier::query()->whereKey($lockedDocumentRequest->dossier_id);
                $dossierQuery->getQuery()->lockForUpdate();
                $lockedDossier = $dossierQuery->firstOrFail();

                $this->dossierTransitions->markInReview($lockedDossier);

                $this->logAuditActivity->handle(
                    AuditEvent::DocumentUploaded,
                    $uploadedDocument,
                    [
                        'source' => 'staff',
                        'original_filename' => $uploadedDocument->original_filename,
                    ],
                );
            },
            SubmissionContext::Staff,
        );
    }
}
