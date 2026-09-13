<?php

declare(strict_types=1);

namespace App\Dossiers\Transitions\DocumentRequests;

use App\Accounts\Models\User;
use App\Dossiers\Enums\DocumentRequestStatus;
use App\Dossiers\Enums\SubmissionContext;
use App\Dossiers\Models\DocumentRequest;
use App\Dossiers\Models\Dossier;
use App\Questionnaires\Enums\QuestionnaireItemType;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

use function count;

/**
 * Guarded status transitions for questionnaire items on a dossier.
 *
 * Allowed flow: pending/rejected/submitted → submitted → accepted|rejected.
 * Submitted → pending when the last multi-file upload is removed (reopenForSubmission).
 * Accepted items cannot be submitted again.
 * Portal submissions are locked while an item awaits review; staff may replace submitted uploads.
 */
final class DocumentRequestTransitions
{
    /**
     * Whether the document request can receive a submission in the given context.
     */
    public function canSubmit(
        DocumentRequest $documentRequest,
        SubmissionContext $context,
        ?Dossier $dossier = null,
    ): bool {
        if ($context === SubmissionContext::Portal) {
            $dossier ??= $documentRequest->dossier;

            if (! $dossier instanceof Dossier || $dossier->status->isLocked()) {
                return false;
            }

            // A request accepting multiple files stays open for further
            // uploads while awaiting review, instead of locking after the
            // first file like a single-file request does.
            if (
                $documentRequest->allowsMultipleUploads()
                && $documentRequest->status === DocumentRequestStatus::Submitted
            ) {
                return true;
            }

            return $documentRequest->status->isAwaitingClient();
        }

        return $documentRequest->status->isAwaitingClient()
            || $documentRequest->status === DocumentRequestStatus::Submitted;
    }

    /**
     * Whether an uploaded file may be removed in the given context.
     *
     * Staff and clients deliberately do NOT get the same rule, and the
     * asymmetry is the decision rather than an oversight:
     *
     * - A client may only remove a file they just added to a request that
     *   accepts several and is still awaiting review. Once an item is accepted
     *   or rejected the answer is part of a reviewed record, and a client
     *   pulling a file out from under that would leave the review describing
     *   something that is no longer there.
     * - Staff may remove any upload while the dossier is open. They are named
     *   in the audit trail, and fixing a client's mistake — the wrong payslip
     *   on an accepted item — is ordinary work that otherwise requires
     *   rejecting and re-requesting a document the client already sent.
     *
     * Both are bounded by the dossier being unlocked: a completed dossier is a
     * closed record for everyone.
     */
    public function canDeleteUpload(
        DocumentRequest $documentRequest,
        SubmissionContext $context,
        ?Dossier $dossier = null,
    ): bool {
        $dossier ??= $documentRequest->dossier;

        if (! $dossier instanceof Dossier || $dossier->status->isLocked()) {
            return false;
        }

        if ($context === SubmissionContext::Staff) {
            return true;
        }

        return $documentRequest->allowsMultipleUploads()
            && $documentRequest->status === DocumentRequestStatus::Submitted;
    }

    /**
     * @param  list<string>|null  $answerOptionIds
     */
    public function submitAnswer(
        DocumentRequest $documentRequest,
        SubmissionContext $context,
        ?string $answerText = null,
        ?bool $answerBoolean = null,
        ?array $answerOptionIds = null,
    ): void {
        throw_if($documentRequest->type === QuestionnaireItemType::File, InvalidArgumentException::class, 'File items must be answered via upload.');

        $this->ensureCanBeSubmitted($documentRequest, $context);

        if ($documentRequest->type->storesAnswerText()) {
            throw_if($answerText === null || mb_trim($answerText) === '', InvalidArgumentException::class, 'A text answer is required.');

            $documentRequest->update([
                'answer_text' => mb_trim($answerText),
                'answer_boolean' => null,
                'answer_option_ids' => null,
                ...$this->submittedState(),
            ]);

            return;
        }

        if ($documentRequest->type->storesAnswerOptionIds()) {
            $this->ensureValidOptionAnswer($documentRequest, $answerOptionIds);

            $documentRequest->update([
                'answer_option_ids' => $answerOptionIds,
                'answer_text' => null,
                'answer_boolean' => null,
                ...$this->submittedState(),
            ]);

            return;
        }

        throw_if($answerBoolean === null, InvalidArgumentException::class, 'A yes/no answer is required.');

        $documentRequest->update([
            'answer_boolean' => $answerBoolean,
            'answer_text' => null,
            'answer_option_ids' => null,
            ...$this->submittedState(),
        ]);
    }

    public function submitUpload(
        DocumentRequest $documentRequest,
        SubmissionContext $context,
    ): void {
        throw_if($documentRequest->type !== QuestionnaireItemType::File, InvalidArgumentException::class, 'Only file items accept uploads.');

        $this->ensureCanBeSubmitted($documentRequest, $context);
        $documentRequest->update($this->submittedState());
    }

    public function accept(DocumentRequest $documentRequest, User $reviewer): void
    {
        $this->ensureCanBeReviewed($documentRequest);

        $documentRequest->update([
            'status' => DocumentRequestStatus::Accepted,
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
            'rejection_reason' => null,
        ]);
    }

    public function reject(
        DocumentRequest $documentRequest,
        User $reviewer,
        ?string $rejectionReason,
    ): void {
        $this->ensureCanBeReviewed($documentRequest);

        $normalizedRejectionReason = $rejectionReason === null
            ? null
            : mb_trim($rejectionReason);

        if ($normalizedRejectionReason === null || $normalizedRejectionReason === '') {
            throw ValidationException::withMessages([
                'rejection_reason' => 'Explain what the client needs to correct.',
            ]);
        }

        $documentRequest->update([
            'status' => DocumentRequestStatus::Rejected,
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
            'rejection_reason' => $normalizedRejectionReason,
        ]);
    }

    /**
     * Reopen a submitted file request after its last upload was removed.
     * Clears answered_at so the item is ready for a new submission.
     */
    public function reopenForSubmission(DocumentRequest $documentRequest): void
    {
        if ($documentRequest->status !== DocumentRequestStatus::Submitted) {
            throw ValidationException::withMessages([
                'document_request' => 'Only a submitted item can be reopened for submission.',
            ]);
        }

        // Callers delete the last upload first; refuse if files still remain.
        if ($documentRequest->uploadedDocuments()->toBase()->exists()) {
            throw ValidationException::withMessages([
                'document_request' => 'Remove all uploaded files before reopening the request.',
            ]);
        }

        $documentRequest->update([
            'status' => DocumentRequestStatus::Pending,
            'answered_at' => null,
        ]);
    }

    private function ensureCanBeSubmitted(
        DocumentRequest $documentRequest,
        SubmissionContext $context,
    ): void {
        if ($this->canSubmit($documentRequest, $context)) {
            return;
        }

        throw ValidationException::withMessages([
            'document_request' => $context === SubmissionContext::Portal
                ? 'This item cannot be submitted from the client portal in its current state.'
                : 'An approved item cannot be submitted again.',
        ]);
    }

    /** @param list<string>|null $answerOptionIds */
    private function ensureValidOptionAnswer(
        DocumentRequest $documentRequest,
        ?array $answerOptionIds,
    ): void {
        throw_if($answerOptionIds === null || $answerOptionIds === [], InvalidArgumentException::class, 'At least one option must be selected.');

        throw_if($documentRequest->type === QuestionnaireItemType::Choice
            && count($answerOptionIds) !== 1, InvalidArgumentException::class, 'Choose exactly one option.');

        $availableOptionIds = array_column(
            $documentRequest->configuration['options'] ?? [],
            'id',
        );

        throw_if(array_diff($answerOptionIds, $availableOptionIds) !== [], InvalidArgumentException::class, 'The selected option is not available.');
    }

    private function ensureCanBeReviewed(DocumentRequest $documentRequest): void
    {
        if ($documentRequest->status === DocumentRequestStatus::Submitted) {
            return;
        }

        throw ValidationException::withMessages([
            'decision' => 'Only submitted items can be reviewed.',
        ]);
    }

    /**
     * Payload shared by answer and upload submissions.
     * Clears prior review metadata so a corrected item can be reviewed again,
     * and drops carried_forward_at so last period's suggestion becomes this
     * period's answer.
     *
     * @return array{
     *     status: DocumentRequestStatus,
     *     answered_at: CarbonInterface,
     *     reviewed_by: null,
     *     reviewed_at: null,
     *     rejection_reason: null,
     *     carried_forward_at: null
     * }
     */
    private function submittedState(): array
    {
        return [
            'status' => DocumentRequestStatus::Submitted,
            'answered_at' => now(),
            'reviewed_by' => null,
            'reviewed_at' => null,
            'rejection_reason' => null,
            'carried_forward_at' => null,
        ];
    }
}
