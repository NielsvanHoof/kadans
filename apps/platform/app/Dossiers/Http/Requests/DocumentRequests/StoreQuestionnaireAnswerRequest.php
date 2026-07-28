<?php

declare(strict_types=1);

namespace App\Dossiers\Http\Requests\DocumentRequests;

use App\Dossiers\Actions\DocumentRequests\ResolveQuestionnaireAnswerRulesAction;
use App\Dossiers\Models\DocumentRequest;
use App\Dossiers\Models\Dossier;
use App\Questionnaires\Enums\QuestionnaireItemType;
use App\Shared\Http\Requests\Concerns\LocalizesValidationAttributes;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

final class StoreQuestionnaireAnswerRequest extends FormRequest
{
    use LocalizesValidationAttributes;

    public function __construct(
        private readonly ResolveQuestionnaireAnswerRulesAction $resolveAnswerRules,
    ) {
        parent::__construct();
    }

    public function authorize(): bool
    {
        $dossier = $this->route('dossier');
        $documentRequest = $this->route('documentRequest');
        $user = $this->user();

        return $user !== null
            && $dossier instanceof Dossier
            && $documentRequest instanceof DocumentRequest
            && $user->can('update', $dossier)
            && $user->can('update', $documentRequest)
            && $documentRequest->type !== QuestionnaireItemType::File;
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
