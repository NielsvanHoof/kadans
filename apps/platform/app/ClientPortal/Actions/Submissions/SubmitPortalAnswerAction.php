<?php

declare(strict_types=1);

namespace App\ClientPortal\Actions\Submissions;

use App\Audit\Actions\LogAuditActivityAction;
use App\Audit\Enums\AuditEvent;
use App\ClientPortal\Actions\Outreach\NotifyWorkspaceIfQuestionnaireCompleteAction;
use App\ClientPortal\Models\ClientAccessGrant;
use App\ClientPortal\Queries\Access\FetchReachableDossiersQuery;
use App\Dossiers\Actions\DocumentRequests\SubmitQuestionnaireAnswerAction;
use App\Dossiers\Enums\SubmissionContext;
use App\Dossiers\Models\DocumentRequest;
use App\Dossiers\Support\DossierLocks;
use App\Dossiers\Transitions\Lifecycle\DossierTransitions;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

final readonly class SubmitPortalAnswerAction
{
    public function __construct(
        private SubmitQuestionnaireAnswerAction $submitQuestionnaireAnswer,
        private LogAuditActivityAction $logAuditActivity,
        private DossierTransitions $dossierTransitions,
        private NotifyWorkspaceIfQuestionnaireCompleteAction $notifyWorkspaceIfQuestionnaireComplete,
        private DossierLocks $dossierLocks,
        private FetchReachableDossiersQuery $fetchReachableDossiers,
    ) {}

    /**
     * @param  list<string>|null  $answerOptionIds
     */
    public function handle(
        DocumentRequest $documentRequest,
        ClientAccessGrant $grant,
        ?string $answerText = null,
        ?bool $answerBoolean = null,
        ?array $answerOptionIds = null,
    ): DocumentRequest {
        return DB::transaction(function () use (
            $documentRequest,
            $grant,
            $answerText,
            $answerBoolean,
            $answerOptionIds,
        ): DocumentRequest {
            [$dossier, $lockedDocumentRequest] = $this->dossierLocks
                ->documentRequestWithDossier($documentRequest);

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

            $submittedDocumentRequest = $this->submitQuestionnaireAnswer->handle(
                $lockedDocumentRequest,
                $answerText,
                $answerBoolean,
                $answerOptionIds,
                SubmissionContext::Portal,
            );

            $this->dossierTransitions->markInReview($dossier);

            $lockedGrant->forceFill(['last_used_at' => now()])->save();

            $this->logAuditActivity->handle(
                AuditEvent::QuestionnaireAnswerSubmitted,
                $submittedDocumentRequest,
                [
                    'source' => 'client_portal',
                    'answer_type' => $submittedDocumentRequest->type->value,
                    'access_grant_id' => $lockedGrant->id,
                ],
            );

            // Toast workspace staff when this was the last outstanding item.
            $this->notifyWorkspaceIfQuestionnaireComplete->handle($dossier);

            return $submittedDocumentRequest;
        });
    }
}
