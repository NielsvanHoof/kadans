<?php

declare(strict_types=1);

namespace App\Questionnaires\Actions;

use App\Questionnaires\Ai\ChecklistDraftAgent;
use App\Questionnaires\Data\DraftedChecklistItemData;
use App\Questionnaires\Enums\QuestionnaireItemType;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Responses\StructuredAgentResponse;

final readonly class DraftQuestionnaireChecklistItemsAction
{
    /**
     * @return list<DraftedChecklistItemData>
     */
    public function handle(string $description, string $languageLabel): array
    {
        $agent = new ChecklistDraftAgent($languageLabel);

        $response = $agent->prompt($description);

        $structured = $response instanceof StructuredAgentResponse ? $response->toArray() : [];

        /** @var list<array{title?: string, instructions?: string, type?: string}> $items */
        $items = $structured['items'] ?? [];

        if ($items === []) {
            Log::warning('Checklist draft agent returned no items.');
        }

        /** @var list<DraftedChecklistItemData> $drafted */
        $drafted = collect($items)
            ->map(fn (array $item): DraftedChecklistItemData => new DraftedChecklistItemData(
                title: (string) ($item['title'] ?? ''),
                instructions: (string) ($item['instructions'] ?? ''),
                type: QuestionnaireItemType::tryFrom((string) ($item['type'] ?? ''))
                    ?? QuestionnaireItemType::File,
            ))
            ->filter(fn (DraftedChecklistItemData $item): bool => $item->title !== '')
            ->values()
            ->all();

        return $drafted;
    }
}
