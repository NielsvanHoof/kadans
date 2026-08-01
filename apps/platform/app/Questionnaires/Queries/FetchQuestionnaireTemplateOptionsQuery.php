<?php

declare(strict_types=1);

namespace App\Questionnaires\Queries;

use App\Dossiers\Data\QuestionnaireTemplateItemOptionData;
use App\Dossiers\Data\QuestionnaireTemplateOptionData;
use App\Questionnaires\Models\QuestionnaireTemplate;

use function count;

/**
 * Template options for the dossier builder apply-template picker.
 * Includes items so the builder can append them into a local draft before Save.
 */
final class FetchQuestionnaireTemplateOptionsQuery
{
    /**
     * @return list<QuestionnaireTemplateOptionData>
     */
    public function handle(): array
    {
        $templates = QuestionnaireTemplate::queryVisibleToCurrentTenant()
            ->with(['items' => fn ($query) => $query->oldest('sort_order')])
            ->oldest('name')
            ->get(['id', 'name', 'category', 'tenant_id']);

        /** @var list<QuestionnaireTemplateOptionData> $templateOptions */
        $templateOptions = [];

        foreach ($templates as $template) {
            /** @var list<QuestionnaireTemplateItemOptionData> $items */
            $items = [];

            foreach ($template->items as $item) {
                $items[] = new QuestionnaireTemplateItemOptionData(
                    type: $item->type->value,
                    fieldKey: $item->field_key,
                    title: $item->title,
                    instructions: $item->instructions,
                    configuration: $item->configuration,
                    sortOrder: $item->sort_order,
                );
            }

            $templateOptions[] = new QuestionnaireTemplateOptionData(
                id: $template->id,
                name: $template->name,
                categoryLabel: $template->category->label(),
                itemsCount: count($items),
                isSystem: $template->isSystem(),
                items: $items,
            );
        }

        return $templateOptions;
    }
}
