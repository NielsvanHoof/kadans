<?php

declare(strict_types=1);

namespace App\Questionnaires\Http\Controllers\ChecklistDraft;

use App\Accounts\Enums\Locale;
use App\Accounts\Http\Concerns\ResolvesCurrentUser;
use App\Questionnaires\Actions\ChecklistDraft\DraftQuestionnaireChecklistItemsAction;
use App\Questionnaires\Data\ChecklistDraft\DraftedChecklistItemData;
use App\Questionnaires\Http\Requests\ChecklistDraft\StoreChecklistDraftRequest;
use App\Questionnaires\Models\QuestionnaireTemplate;
use App\Shared\Http\Controllers\Controller;
use App\Tenancy\Models\Tenant;
use Illuminate\Http\JsonResponse;

final class QuestionnaireChecklistDraftController extends Controller
{
    use ResolvesCurrentUser;

    public function store(
        Tenant $tenant,
        StoreChecklistDraftRequest $request,
        QuestionnaireTemplate $template,
        DraftQuestionnaireChecklistItemsAction $draftQuestionnaireChecklistItems,
    ): JsonResponse {
        $languageLabel = (Locale::tryFrom($this->currentUser()->preferredLocale()) ?? Locale::English)->label();

        $items = $draftQuestionnaireChecklistItems->handle(
            $request->validated('description'),
            $languageLabel,
        );

        return response()->json([
            'items' => array_map(
                fn (DraftedChecklistItemData $item): array => $item->toArray(),
                $items,
            ),
        ]);
    }
}
