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

final class StoreDocumentRequestRequest extends FormRequest
{
    use LocalizesValidationAttributes, ValidatesQuestionnaireItemConfiguration;

    /**
     * Require view access on the dossier plus permission to create document requests.
     */
    public function authorize(): bool
    {
        $dossier = $this->route('dossier');

        if (! $dossier instanceof Dossier) {
            return false;
        }

        $user = $this->user();

        return $user !== null
            && $user->can('update', $dossier)
            && $user->can('create', DocumentRequest::class);
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(QuestionnaireItemType::class)],
            'title' => ['required', 'string', 'max:255'],
            'instructions' => ['nullable', 'string', 'max:2000'],
            ...$this->questionnaireConfigurationRules(),
            // Zero-based canvas index. Omit to append at the end.
            'position' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:1000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $insertPosition = $this->insertPosition();

            $this->validateVisibilitySource(
                $validator,
                // Existing sort_order values are dense, so anything before the
                // insert index ends up above the new field once it is saved.
                fn (DocumentRequest $source): bool => $source->sort_order < $insertPosition,
            );
        });
    }

    /**
     * Zero-based index the new field will occupy. Omitting `position` appends the
     * field, which makes every existing field an eligible visibility source.
     */
    private function insertPosition(): int
    {
        $position = $this->input('position');

        if (! is_numeric($position)) {
            return PHP_INT_MAX;
        }

        return (int) $position;
    }
}
