<?php

declare(strict_types=1);

namespace App\Questionnaires\Http\Controllers;

use App\Questionnaires\Actions\CreateQuestionnaireTemplateItemAction;
use App\Questionnaires\Actions\DeleteQuestionnaireTemplateItemAction;
use App\Questionnaires\Actions\ReorderQuestionnaireTemplateItemsAction;
use App\Questionnaires\Actions\UpdateQuestionnaireTemplateItemAction;
use App\Questionnaires\Http\Requests\ReorderQuestionnaireTemplateItemsRequest;
use App\Questionnaires\Http\Requests\StoreQuestionnaireTemplateItemRequest;
use App\Questionnaires\Http\Requests\UpdateQuestionnaireTemplateItemRequest;
use App\Questionnaires\Models\QuestionnaireTemplate;
use App\Questionnaires\Models\QuestionnaireTemplateItem;
use App\Shared\Http\Controllers\Controller;
use App\Tenancy\Models\Tenant;
use Illuminate\Http\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

final class QuestionnaireTemplateItemController extends Controller
{
    public function store(
        Tenant $tenant,
        StoreQuestionnaireTemplateItemRequest $request,
        QuestionnaireTemplate $template,
        CreateQuestionnaireTemplateItemAction $createQuestionnaireTemplateItem,
    ): RedirectResponse {
        $createQuestionnaireTemplateItem->handle($template, $request->validated());

        return to_route(
            'workspaces.templates.show',
            $this->workspaceRouteParameters(['template' => $template]),
        );
    }

    public function update(
        Tenant $tenant,
        UpdateQuestionnaireTemplateItemRequest $request,
        QuestionnaireTemplate $template,
        QuestionnaireTemplateItem $item,
        UpdateQuestionnaireTemplateItemAction $updateQuestionnaireTemplateItem,
    ): RedirectResponse {
        $updateQuestionnaireTemplateItem->handle($item, $request->validated());

        return to_route(
            'workspaces.templates.show',
            $this->workspaceRouteParameters(['template' => $template]),
        );
    }

    public function destroy(
        Tenant $tenant,
        QuestionnaireTemplate $template,
        QuestionnaireTemplateItem $item,
        DeleteQuestionnaireTemplateItemAction $deleteQuestionnaireTemplateItem,
    ): RedirectResponse {
        $this->authorize('update', $template);

        $deleteQuestionnaireTemplateItem->handle($template, $item);

        return to_route(
            'workspaces.templates.show',
            $this->workspaceRouteParameters(['template' => $template]),
        );
    }

    public function reorder(
        Tenant $tenant,
        ReorderQuestionnaireTemplateItemsRequest $request,
        QuestionnaireTemplate $template,
        ReorderQuestionnaireTemplateItemsAction $reorderQuestionnaireTemplateItems,
    ): RedirectResponse|Response {
        $reorderQuestionnaireTemplateItems->handle(
            $template,
            $request->array('item_ids'),
        );

        if ($request->expectsJson()) {
            return response()->noContent();
        }

        return to_route(
            'workspaces.templates.show',
            $this->workspaceRouteParameters(['template' => $template]),
        );
    }
}
