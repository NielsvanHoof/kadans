<?php

declare(strict_types=1);

namespace App\Questionnaires\Actions;

use App\Questionnaires\Models\QuestionnaireTemplateItem;

/**
 * Persist updated questionnaire template item attributes.
 */
final class UpdateQuestionnaireTemplateItemAction
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(QuestionnaireTemplateItem $item, array $attributes): void
    {
        $item->update($attributes);
    }
}
