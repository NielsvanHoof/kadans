<?php

declare(strict_types=1);

namespace App\Questionnaires\Queries;

use App\Dossiers\Data\QuestionnaireTemplateOptionData;
use App\Questionnaires\Models\QuestionnaireTemplate;

/**
 * Template options for the dossier builder apply-template picker.
 */
final class FetchQuestionnaireTemplateOptionsQuery
{
    /**
     * @return list<QuestionnaireTemplateOptionData>
     */
    public function handle(): array
    {
        $templates = QuestionnaireTemplate::queryVisibleToCurrentTenant()
            ->withCount('items')
            ->oldest('name')
            ->get(['id', 'name', 'category', 'tenant_id']);

        /** @var list<QuestionnaireTemplateOptionData> $templateOptions */
        $templateOptions = [];

        foreach ($templates as $template) {
            $templateOptions[] = new QuestionnaireTemplateOptionData(
                id: $template->id,
                name: $template->name,
                categoryLabel: $template->category->label(),
                itemsCount: (int) ($template->items_count ?? 0),
                isSystem: $template->isSystem(),
            );
        }

        return $templateOptions;
    }
}
