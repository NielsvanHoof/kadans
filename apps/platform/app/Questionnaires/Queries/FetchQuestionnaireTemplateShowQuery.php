<?php

declare(strict_types=1);

namespace App\Questionnaires\Queries;

use App\Questionnaires\Data\QuestionnaireTemplateItemData;
use App\Questionnaires\Data\QuestionnaireTemplateShowData;
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
                type: $item->type->value,
                title: $item->title,
                instructions: $item->instructions,
                sortOrder: $item->sort_order,
            );
        }

        return new QuestionnaireTemplateShowData(
            id: $template->id,
            name: $template->name,
            description: $template->description,
            category: $template->category->value,
            categoryLabel: $template->category->label(),
            isSystem: $template->isSystem(),
            items: $items,
        );
    }
}
