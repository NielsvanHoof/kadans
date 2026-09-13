<?php

declare(strict_types=1);

namespace App\Dossiers\Actions\DocumentRequests;

use App\Audit\Actions\LogAuditActivityAction;
use App\Audit\Enums\AuditEvent;
use App\Dossiers\Enums\SubmissionContext;
use App\Dossiers\Models\DocumentRequest;
use App\Dossiers\Support\DossierLocks;
use App\Dossiers\Transitions\Lifecycle\DossierTransitions;
use Illuminate\Support\Facades\DB;

/**
 * Record an answer received outside the portal and preserve staff attribution.
 */
final readonly class SubmitStaffQuestionnaireAnswerAction
{
    public function __construct(
        private SubmitQuestionnaireAnswerAction $submitQuestionnaireAnswer,
        private DossierTransitions $dossierTransitions,
        private LogAuditActivityAction $logAuditActivity,
        private DossierLocks $dossierLocks,
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
            [$dossier, $lockedDocumentRequest] = $this->dossierLocks
                ->documentRequestWithDossier($documentRequest);

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
