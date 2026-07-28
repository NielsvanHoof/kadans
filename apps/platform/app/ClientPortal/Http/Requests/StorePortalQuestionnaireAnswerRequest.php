<?php

declare(strict_types=1);

namespace App\ClientPortal\Http\Requests;

use App\ClientPortal\Enums\SubmissionContext;
use App\ClientPortal\Http\Middleware\ResolveClientPortalGrant;
use App\ClientPortal\Models\ClientAccessGrant;
use App\Dossiers\Models\DocumentRequest;
use App\Dossiers\Models\Dossier;
use App\Dossiers\Transitions\DocumentRequestTransitions;
use App\Questionnaires\Enums\QuestionnaireItemType;
use App\Shared\Http\Requests\Concerns\LocalizesValidationAttributes;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

final class StorePortalQuestionnaireAnswerRequest extends FormRequest
{
    use LocalizesValidationAttributes;

    public function __construct(
        private readonly DocumentRequestTransitions $documentRequestTransitions,
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

        return $grant instanceof ClientAccessGrant
            && $documentRequest instanceof DocumentRequest
            && $dossier instanceof Dossier
            && $documentRequest->dossier_id === $grant->dossier_id
            && $documentRequest->type !== QuestionnaireItemType::File
            && $this->documentRequestTransitions->canSubmit(
                $documentRequest,
                SubmissionContext::Portal,
                $dossier,
            );
    }

    /** @return array<string, list<ValidationRule|string>> */
    public function rules(): array
    {
        $documentRequest = $this->route('documentRequest');

        if ($documentRequest instanceof DocumentRequest
            && $documentRequest->type === QuestionnaireItemType::Boolean) {
            return [
                'answer_boolean' => ['required', 'boolean'],
            ];
        }

        if ($documentRequest instanceof DocumentRequest
            && $documentRequest->type === QuestionnaireItemType::Date) {
            return [
                'answer_text' => ['required', 'date_format:Y-m-d'],
            ];
        }

        if ($documentRequest instanceof DocumentRequest
            && $documentRequest->type === QuestionnaireItemType::Number) {
            return [
                'answer_text' => ['required', 'numeric'],
            ];
        }

        return [
            'answer_text' => ['required', 'string', 'max:5000'],
        ];
    }
}
