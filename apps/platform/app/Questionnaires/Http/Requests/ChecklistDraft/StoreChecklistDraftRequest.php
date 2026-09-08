<?php

declare(strict_types=1);

namespace App\Questionnaires\Http\Requests\ChecklistDraft;

use App\Questionnaires\Models\QuestionnaireTemplate;
use App\Shared\Http\Requests\Concerns\LocalizesValidationAttributes;
use Illuminate\Foundation\Http\FormRequest;

final class StoreChecklistDraftRequest extends FormRequest
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

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'description' => ['required', 'string', 'max:500'],
        ];
    }
}
