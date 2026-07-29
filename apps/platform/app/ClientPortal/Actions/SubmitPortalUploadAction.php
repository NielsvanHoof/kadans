<?php

declare(strict_types=1);

namespace App\ClientPortal\Actions;

use App\Audit\Actions\LogAuditActivityAction;
use App\Audit\Enums\AuditEvent;
use App\ClientPortal\Enums\SubmissionContext;
use App\ClientPortal\Models\ClientAccessGrant;
use App\Dossiers\Actions\DocumentRequests\UploadDocumentForRequestAction;
use App\Dossiers\Models\DocumentRequest;
use App\Dossiers\Models\Dossier;
use App\Dossiers\Models\UploadedDocument;
use App\Dossiers\Transitions\DossierTransitions;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;

final readonly class SubmitPortalUploadAction
{
    public function __construct(
        private UploadDocumentForRequestAction $uploadDocumentForRequest,
        private LogAuditActivityAction $logAuditActivity,
        private DossierTransitions $dossierTransitions,
        private NotifyWorkspaceIfQuestionnaireCompleteAction $notifyWorkspaceIfQuestionnaireComplete,
    ) {}

    public function handle(
        DocumentRequest $documentRequest,
        ClientAccessGrant $grant,
        UploadedFile $file,
    ): UploadedDocument {
        return $this->uploadDocumentForRequest->handle(
            $documentRequest,
            $file,
            function (
                UploadedDocument $uploadedDocument,
                DocumentRequest $lockedDocumentRequest,
            ) use ($grant): void {
                $dossierQuery = Dossier::query()->whereKey($lockedDocumentRequest->dossier_id);
                $dossierQuery->getQuery()->lockForUpdate();
                $dossier = $dossierQuery->firstOrFail();

                $this->dossierTransitions->markInReview($dossier);

                $grantQuery = ClientAccessGrant::query()->whereKey($grant->getKey());
                $grantQuery->getQuery()->lockForUpdate();
                $lockedGrant = $grantQuery->firstOrFail();

                if ($lockedGrant->dossier_id !== $dossier->id) {
                    throw (new ModelNotFoundException)->setModel(
                        DocumentRequest::class,
                        [$lockedDocumentRequest->getKey()],
                    );
                }

                $lockedGrant->forceFill(['last_used_at' => now()])->save();

                $this->logAuditActivity->handle(
                    AuditEvent::DocumentUploaded,
                    $uploadedDocument,
                    [
                        'source' => 'client_portal',
                        'access_grant_id' => $lockedGrant->id,
                    ],
                );

                // Toast workspace staff when this was the last outstanding item.
                $this->notifyWorkspaceIfQuestionnaireComplete->handle($dossier);
            },
            SubmissionContext::Portal,
        );
    }
}
