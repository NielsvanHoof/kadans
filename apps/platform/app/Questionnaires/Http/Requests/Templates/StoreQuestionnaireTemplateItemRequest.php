<?php

declare(strict_types=1);

namespace App\Questionnaires\Http\Requests\Templates;

use App\Questionnaires\Enums\QuestionnaireItemType;
use App\Questionnaires\Models\QuestionnaireTemplate;
use App\Shared\Http\Requests\Concerns\LocalizesValidationAttributes;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreQuestionnaireTemplateItemRequest extends FormRequest
{
    use LocalizesValidationAttributes;

    public function authorize(): bool
    {
        $template = $this->route('template');
        $user = $this->user();

        return $user !== null
            && $template instanceof QuestionnaireTemplate
            && $user->can('update', $template);
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(QuestionnaireItemType::class)],
            'title' => ['required', 'string', 'max:255'],
            'instructions' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
