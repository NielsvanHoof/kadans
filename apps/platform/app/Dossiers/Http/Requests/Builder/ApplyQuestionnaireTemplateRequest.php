<?php

declare(strict_types=1);

namespace App\Dossiers\Http\Requests\Builder;

use App\Dossiers\Http\Requests\Concerns\AuthorizesDossierBuilderMutation;
use App\Questionnaires\Models\QuestionnaireTemplate;
use App\Shared\Http\Requests\Concerns\LocalizesValidationAttributes;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ApplyQuestionnaireTemplateRequest extends FormRequest
{
    use AuthorizesDossierBuilderMutation;
    use LocalizesValidationAttributes;

    public function authorize(): bool
    {
        if (! $this->userHoldsBuilderLockForMutation()) {
            return false;
        }

        $user = $this->user();
        $templateId = $this->input('questionnaire_template_id');

        if ($user === null || ! is_numeric($templateId)) {
            return false;
        }

        $template = QuestionnaireTemplate::queryVisibleToCurrentTenant()
            ->find((int) $templateId);

        return $template instanceof QuestionnaireTemplate
            && $user->can('apply', $template);
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'questionnaire_template_id' => [
                'required',
                'integer',
                Rule::exists('questionnaire_templates', 'id'),
            ],
        ];
    }
}
