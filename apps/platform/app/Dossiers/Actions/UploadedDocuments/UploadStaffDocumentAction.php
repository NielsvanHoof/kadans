<?php

declare(strict_types=1);

namespace App\Dossiers\Actions\UploadedDocuments;

use App\Audit\Actions\LogAuditActivityAction;
use App\Audit\Enums\AuditEvent;
use App\Dossiers\Enums\SubmissionContext;
use App\Dossiers\Models\DocumentRequest;
use App\Dossiers\Models\Dossier;
use App\Dossiers\Models\UploadedDocument;
use App\Dossiers\Support\Uploads\StagedUpload;
use App\Dossiers\Transitions\Lifecycle\DossierTransitions;

/**
 * Staff upload: move the staged file into place, mark the dossier in review, and audit.
 */
final readonly class UploadStaffDocumentAction
{
    public function __construct(
        private UploadDocumentForRequestAction $uploadDocumentForRequest,
        private LogAuditActivityAction $logAuditActivity,
        private DossierTransitions $dossierTransitions,
    ) {}

    public function handle(
        DocumentRequest $documentRequest,
        StagedUpload $upload,
    ): UploadedDocument {
        return $this->uploadDocumentForRequest->handle(
            $documentRequest,
            $upload,
            function (
                UploadedDocument $uploadedDocument,
                DocumentRequest $lockedDocumentRequest,
                Dossier $lockedDossier,
            ): void {
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
