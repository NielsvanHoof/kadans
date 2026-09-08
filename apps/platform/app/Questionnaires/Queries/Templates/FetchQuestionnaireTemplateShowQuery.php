<?php

declare(strict_types=1);

namespace App\Questionnaires\Queries\Templates;

use App\Questionnaires\Data\Templates\QuestionnaireTemplateItemData;
use App\Questionnaires\Data\Templates\QuestionnaireTemplateShowData;
use App\Questionnaires\Models\QuestionnaireTemplate;

final class FetchQuestionnaireTemplateShowQuery
{
    public function handle(QuestionnaireTemplate $template): QuestionnaireTemplateShowData
    {
        $template->load([
            'items' => fn ($query) => $query
                ->oldest('sort_order')
                ->oldest('id'),
        ]);

        /** @var list<QuestionnaireTemplateItemData> $items */
        $items = [];

        foreach ($template->items as $item) {
            $items[] = new QuestionnaireTemplateItemData(
                id: $item->id,
                type: $item->type,
                title: $item->title,
                instructions: $item->instructions,
                sortOrder: $item->sort_order,
            );
        }

        return new QuestionnaireTemplateShowData(
            id: $template->id,
            name: $template->name,
            description: $template->description,
            category: $template->category,
            categoryLabel: $template->category->label(),
            isSystem: $template->isSystem(),
            items: $items,
        );
    }
}
