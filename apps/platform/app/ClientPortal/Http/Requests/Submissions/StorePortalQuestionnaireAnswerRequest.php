<?php

declare(strict_types=1);

namespace App\ClientPortal\Http\Requests\Submissions;

use App\ClientPortal\Http\Middleware\ResolveClientPortalGrant;
use App\ClientPortal\Models\ClientAccessGrant;
use App\Dossiers\Actions\DocumentRequests\DetermineDocumentRequestVisibilityAction;
use App\Dossiers\Actions\DocumentRequests\ResolveQuestionnaireAnswerRulesAction;
use App\Dossiers\Enums\SubmissionContext;
use App\Dossiers\Models\DocumentRequest;
use App\Dossiers\Models\Dossier;
use App\Dossiers\Transitions\DocumentRequests\DocumentRequestTransitions;
use App\Questionnaires\Enums\QuestionnaireItemType;
use App\Shared\Http\Requests\Concerns\LocalizesValidationAttributes;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

final class StorePortalQuestionnaireAnswerRequest extends FormRequest
{
    use LocalizesValidationAttributes;

    public function __construct(
        private readonly DocumentRequestTransitions $documentRequestTransitions,
        private readonly DetermineDocumentRequestVisibilityAction $determineVisibility,
        private readonly ResolveQuestionnaireAnswerRulesAction $resolveAnswerRules,
    ) {
        parent::__construct();
    }

    public function authorize(): bool
    {
        $grant = $this->attributes->get(ResolveClientPortalGrant::REQUEST_ATTRIBUTE);
        $documentRequest = $this->route('documentRequest');
        $dossier = $documentRequest instanceof DocumentRequest
            ? $documentRequest->dossier
            : null;

        if (! $grant instanceof ClientAccessGrant
            || ! $documentRequest instanceof DocumentRequest
            || ! $dossier instanceof Dossier
            || $documentRequest->dossier_id !== $grant->dossier_id
            || $documentRequest->type === QuestionnaireItemType::File
            || ! $this->documentRequestTransitions->canSubmit(
                $documentRequest,
                SubmissionContext::Portal,
                $dossier,
            )) {
            return false;
        }

        // Hidden (not applicable) fields cannot receive new answers from the portal.
        return $this->determineVisibility->handle(
            $documentRequest,
            $dossier->documentRequestsInCanvasOrder(),
        );
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        $documentRequest = $this->route('documentRequest');

        // Route binding guarantees the model, so there is nothing to validate otherwise.
        if (! $documentRequest instanceof DocumentRequest) {
            return [];
        }

        return $this->resolveAnswerRules->handle($documentRequest);
    }
}
