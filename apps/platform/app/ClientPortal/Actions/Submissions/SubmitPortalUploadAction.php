<?php

declare(strict_types=1);

namespace App\ClientPortal\Actions\Submissions;

use App\Audit\Actions\LogAuditActivityAction;
use App\Audit\Enums\AuditEvent;
use App\ClientPortal\Actions\Outreach\NotifyWorkspaceIfQuestionnaireCompleteAction;
use App\ClientPortal\Models\ClientAccessGrant;
use App\ClientPortal\Queries\Access\FetchReachableDossiersQuery;
use App\Dossiers\Actions\UploadedDocuments\UploadDocumentForRequestAction;
use App\Dossiers\Enums\SubmissionContext;
use App\Dossiers\Models\DocumentRequest;
use App\Dossiers\Models\Dossier;
use App\Dossiers\Models\UploadedDocument;
use App\Dossiers\Support\Uploads\DocumentUploadLimits;
use App\Dossiers\Support\Uploads\StagedUpload;
use App\Dossiers\Transitions\Lifecycle\DossierTransitions;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

final readonly class SubmitPortalUploadAction
{
    public function __construct(
        private UploadDocumentForRequestAction $uploadDocumentForRequest,
        private LogAuditActivityAction $logAuditActivity,
        private DossierTransitions $dossierTransitions,
        private NotifyWorkspaceIfQuestionnaireCompleteAction $notifyWorkspaceIfQuestionnaireComplete,
        private FetchReachableDossiersQuery $fetchReachableDossiers,
    ) {}

    public function handle(
        DocumentRequest $documentRequest,
        ClientAccessGrant $grant,
        StagedUpload $upload,
    ): UploadedDocument {
        return $this->uploadDocumentForRequest->handle(
            $documentRequest,
            $upload,
            function (
                UploadedDocument $uploadedDocument,
                DocumentRequest $lockedDocumentRequest,
                Dossier $dossier,
            ) use ($grant): void {
                // The cap's only enforceable check. StorePortalUploadedDocumentRequest
                // sums bytes before the write, so concurrent POSTs all read the same
                // pre-write total and every one of them passes. This runs inside the
                // transaction that already holds the dossier lock (see DossierLocks),
                // which serialises every portal upload on the dossier, and after the
                // row is written — so the sum includes the file being added, and a
                // replacement counts once rather than twice.
                //
                // Throwing here also lands before startPipeline, so an over-cap file
                // never costs an OCR run. The rollback is what the cap is for.
                if (
                    DocumentUploadLimits::portalBytesUsedForDossier($dossier->id)
                    > DocumentUploadLimits::MAX_PORTAL_BYTES_PER_DOSSIER
                ) {
                    throw ValidationException::withMessages([
                        'documents' => DocumentUploadLimits::portalCapMessage(),
                    ]);
                }

                $this->dossierTransitions->markInReview($dossier);

                // The grant is locked last — see DossierLocks for the order.
                $grantQuery = ClientAccessGrant::query()->whereKey($grant->getKey());
                $grantQuery->getQuery()->lockForUpdate();
                $lockedGrant = $grantQuery->firstOrFail();

                // Re-checked under the grant lock, against the same predicate the
                // HTTP layer used: a client-scoped grant's reach is a live query,
                // so the dossier could have been completed, archived or moved out
                // of reach between the request check and this write.
                if (! $this->fetchReachableDossiers->covers($lockedGrant, $dossier->id)) {
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
