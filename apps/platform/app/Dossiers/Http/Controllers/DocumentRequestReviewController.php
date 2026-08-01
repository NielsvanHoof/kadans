<?php

declare(strict_types=1);

namespace App\Dossiers\Http\Controllers;

use App\Accounts\Http\Concerns\ResolvesCurrentUser;
use App\Dossiers\Actions\DocumentRequests\ReviewDocumentRequestAction;
use App\Dossiers\Actions\DocumentRequests\SubmitStaffQuestionnaireAnswerAction;
use App\Dossiers\Enums\DocumentRequestStatus;
use App\Dossiers\Http\Requests\DocumentRequests\ReviewDocumentRequestRequest;
use App\Dossiers\Http\Requests\DocumentRequests\StoreQuestionnaireAnswerRequest;
use App\Dossiers\Models\DocumentRequest;
use App\Dossiers\Models\Dossier;
use App\Shared\Http\Controllers\Controller;
use App\Shared\Http\Toast;
use App\Tenancy\Models\Tenant;
use Illuminate\Http\RedirectResponse;

use function array_key_exists;
use function is_string;

/**
 * Review-stage mutations: approve/request changes, and staff answers on behalf of the client.
 */
final class DocumentRequestReviewController extends Controller
{
    use ResolvesCurrentUser;

    public function store(
        Tenant $tenant,
        ReviewDocumentRequestRequest $request,
        Dossier $dossier,
        DocumentRequest $documentRequest,
        ReviewDocumentRequestAction $reviewDocumentRequest,
    ): RedirectResponse {
        $user = $this->currentUser();

        $decision = DocumentRequestStatus::from((string) $request->validated('decision'));
        $rejectionReason = $request->validated('rejection_reason');

        $reviewDocumentRequest->handle(
            $documentRequest,
            $user,
            $decision,
            is_string($rejectionReason) ? $rejectionReason : null,
        );

        Toast::success($decision === DocumentRequestStatus::Accepted
            ? __('Item approved.')
            : __('Changes requested. The client notification has been queued.'));

        return $this->redirectToReview($dossier);
    }

    public function answer(
        Tenant $tenant,
        StoreQuestionnaireAnswerRequest $request,
        Dossier $dossier,
        DocumentRequest $documentRequest,
        SubmitStaffQuestionnaireAnswerAction $submitStaffQuestionnaireAnswer,
    ): RedirectResponse {
        $validated = $request->validated();

        $submitStaffQuestionnaireAnswer->handle(
            $documentRequest,
            $validated['answer_text'] ?? null,
            array_key_exists('answer_boolean', $validated)
                ? (bool) $validated['answer_boolean']
                : null,
            isset($validated['answer_option_ids'])
                ? array_values($validated['answer_option_ids'])
                : null,
        );

        Toast::success(__('Answer saved on behalf of the client.'));

        return $this->redirectToReview($dossier);
    }

    private function redirectToReview(Dossier $dossier): RedirectResponse
    {
        return to_route(
            'workspaces.dossiers.review',
            $this->workspaceRouteParameters(['dossier' => $dossier]),
        );
    }
}
