<?php

declare(strict_types=1);

namespace App\Dossiers\Http\Requests\Builder;

use App\Dossiers\Http\Requests\Builder\Concerns\ValidatesQuestionnaireItemConfiguration;
use App\Dossiers\Models\DocumentRequest;
use App\Dossiers\Models\Dossier;
use App\Questionnaires\Enums\QuestionnaireItemType;
use App\Shared\Http\Requests\Concerns\LocalizesValidationAttributes;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class UpdateDocumentRequestRequest extends FormRequest
{
    use LocalizesValidationAttributes, ValidatesQuestionnaireItemConfiguration;

    public function authorize(): bool
    {
        $dossier = $this->route('dossier');
        $documentRequest = $this->route('documentRequest');
        $user = $this->user();

        return $user !== null
            && $dossier instanceof Dossier
            && $documentRequest instanceof DocumentRequest
            && $user->can('update', $dossier)
            && $user->can('update', $documentRequest);
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
