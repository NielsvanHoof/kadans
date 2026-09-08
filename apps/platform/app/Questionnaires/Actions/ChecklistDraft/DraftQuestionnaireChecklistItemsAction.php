<?php

declare(strict_types=1);

namespace App\Questionnaires\Actions\ChecklistDraft;

use App\Questionnaires\Ai\ChecklistDraft\ChecklistDraftAgent;
use App\Questionnaires\Data\ChecklistDraft\DraftedChecklistItemData;
use App\Questionnaires\Enums\QuestionnaireItemType;
use App\Tenancy\Enums\TenantAiFeature;
use App\Tenancy\Queries\Settings\ResolveTenantSettingsQuery;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Responses\StructuredAgentResponse;

final readonly class DraftQuestionnaireChecklistItemsAction
{
    public function __construct(private ResolveTenantSettingsQuery $resolveTenantSettings) {}

    /**
     * @return list<DraftedChecklistItemData>
     */
    public function handle(string $description, string $languageLabel): array
    {
        if (! $this->resolveTenantSettings->isAiFeatureEnabled(TenantAiFeature::ChecklistDraft)) {
            throw new AuthorizationException(__('AI checklist suggestions are disabled for this firm.'));
        }

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
