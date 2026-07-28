<?php

declare(strict_types=1);

namespace App\Dossiers\Actions\DocumentRequests;

use App\Audit\Actions\LogAuditActivityAction;
use App\Audit\Enums\AuditEvent;
use App\ClientPortal\Enums\SubmissionContext;
use App\Dossiers\Models\DocumentRequest;
use App\Dossiers\Models\Dossier;
use App\Dossiers\Transitions\DossierTransitions;
use Illuminate\Support\Facades\DB;

/**
 * Record an answer received outside the portal and preserve staff attribution.
 */
final class SubmitStaffQuestionnaireAnswerAction
{
    public function __construct(
        private readonly SubmitQuestionnaireAnswerAction $submitQuestionnaireAnswer,
        private readonly DossierTransitions $dossierTransitions,
        private readonly LogAuditActivityAction $logAuditActivity,
    ) {}

    /**
     * @param  list<string>|null  $answerOptionIds
     */
    public function handle(
        DocumentRequest $documentRequest,
        ?string $answerText = null,
        ?bool $answerBoolean = null,
        ?array $answerOptionIds = null,
    ): DocumentRequest {
        return DB::transaction(function () use (
            $documentRequest,
            $answerText,
            $answerBoolean,
            $answerOptionIds,
        ): DocumentRequest {
            $documentRequestQuery = DocumentRequest::query()
                ->whereKey($documentRequest->getKey());
            $documentRequestQuery->getQuery()->lockForUpdate();
            $lockedDocumentRequest = $documentRequestQuery->firstOrFail();

            $dossierQuery = Dossier::query()
                ->whereKey($lockedDocumentRequest->dossier_id);
            $dossierQuery->getQuery()->lockForUpdate();
            $dossier = $dossierQuery->firstOrFail();

            $submittedDocumentRequest = $this->submitQuestionnaireAnswer->handle(
                $lockedDocumentRequest,
                $answerText,
                $answerBoolean,
                $answerOptionIds,
                SubmissionContext::Staff,
            );

            $this->dossierTransitions->markInReview($dossier);

            $this->logAuditActivity->handle(
                AuditEvent::QuestionnaireAnswerSubmitted,
                $submittedDocumentRequest,
                [
                    'source' => 'staff',
                    'answer_type' => $submittedDocumentRequest->type->value,
                ],
            );

            return $submittedDocumentRequest;
        });
    }
}
