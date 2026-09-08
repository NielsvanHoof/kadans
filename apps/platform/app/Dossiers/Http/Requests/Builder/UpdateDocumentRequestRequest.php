<?php

declare(strict_types=1);

namespace App\Dossiers\Http\Requests\Builder;

use App\Dossiers\Http\Requests\Builder\Concerns\AuthorizesDossierBuilderMutation;
use App\Dossiers\Http\Requests\Builder\Concerns\ValidatesQuestionnaireItemConfiguration;
use App\Dossiers\Models\DocumentRequest;
use App\Questionnaires\Enums\QuestionnaireItemType;
use App\Shared\Http\Requests\Concerns\LocalizesValidationAttributes;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateDocumentRequestRequest extends FormRequest
{
    use AuthorizesDossierBuilderMutation;
    use LocalizesValidationAttributes;
    use ValidatesQuestionnaireItemConfiguration;

    public function authorize(): bool
    {
        $documentRequest = $this->route('documentRequest');

        return $this->userHoldsBuilderLockForMutation()
            && $documentRequest instanceof DocumentRequest
            && $this->user()?->can('update', $documentRequest) === true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(QuestionnaireItemType::class)],
            'title' => ['required', 'string', 'max:255'],
            'instructions' => ['nullable', 'string', 'max:2000'],
            ...$this->questionnaireConfigurationRules(),
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $documentRequest = $this->route('documentRequest');

        if (! $documentRequest instanceof DocumentRequest) {
            return;
        }

        $validator->after(function (Validator $validator) use ($documentRequest): void {
            $this->validateVisibilitySource(
                $validator,
                // A field can never point at itself, so this also blocks self-reference.
                fn (DocumentRequest $source): bool => $source->isOrderedBefore($documentRequest),
            );
        });
    }
}
