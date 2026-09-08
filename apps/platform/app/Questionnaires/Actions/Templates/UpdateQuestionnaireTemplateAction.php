<?php

declare(strict_types=1);

namespace App\Questionnaires\Actions;

use App\Questionnaires\Models\QuestionnaireTemplate;

/**
 * Persist updated questionnaire template attributes.
 */
final class UpdateQuestionnaireTemplateAction
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(QuestionnaireTemplate $template, array $attributes): void
    {
        $template->update($attributes);
    }
}
