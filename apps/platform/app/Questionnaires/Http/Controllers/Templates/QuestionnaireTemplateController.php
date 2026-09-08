<?php

declare(strict_types=1);

namespace App\Questionnaires\Http\Controllers;

use App\Questionnaires\Actions\CopyQuestionnaireTemplateAction;
use App\Questionnaires\Actions\CreateQuestionnaireTemplateAction;
use App\Questionnaires\Actions\DeleteQuestionnaireTemplateAction;
use App\Questionnaires\Actions\UpdateQuestionnaireTemplateAction;
use App\Questionnaires\Enums\QuestionnaireTemplateCategory;
use App\Questionnaires\Http\Requests\StoreQuestionnaireTemplateRequest;
use App\Questionnaires\Http\Requests\UpdateQuestionnaireTemplateRequest;
use App\Questionnaires\Models\QuestionnaireTemplate;
use App\Questionnaires\Queries\FetchQuestionnaireTemplateShowQuery;
use App\Questionnaires\Queries\FetchQuestionnaireTemplatesQuery;
use App\Shared\Http\Controllers\Controller;
use App\Tenancy\Models\Tenant;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

final class QuestionnaireTemplateController extends Controller
{
    public function index(
        Tenant $tenant,
        FetchQuestionnaireTemplatesQuery $fetchQuestionnaireTemplates,
    ): Response {
        $this->authorize('viewAny', QuestionnaireTemplate::class);

        return Inertia::render('workspaces/questionnaires/index', [
            ...$fetchQuestionnaireTemplates->handle()->toArray(),
            'categories' => collect(QuestionnaireTemplateCategory::cases())
                ->map(fn (QuestionnaireTemplateCategory $category): array => [
                    'value' => $category->value,
                    'label' => $category->label(),
                ]),
            'can_manage' => request()->user()?->can('create', QuestionnaireTemplate::class) ?? false,
            ...$fetchQuestionnaireTemplates->indexQueryProps(),
        ]);
    }

    public function create(Tenant $tenant): Response
    {
        $this->authorize('create', QuestionnaireTemplate::class);

        return Inertia::render('workspaces/questionnaires/create', [
            'categories' => collect(QuestionnaireTemplateCategory::cases())
                ->map(fn (QuestionnaireTemplateCategory $category): array => [
                    'value' => $category->value,
                    'label' => $category->label(),
                ]),
        ]);
    }

    public function store(
        Tenant $tenant,
        StoreQuestionnaireTemplateRequest $request,
        CreateQuestionnaireTemplateAction $createQuestionnaireTemplate,
    ): RedirectResponse {
        $template = $createQuestionnaireTemplate->handle($tenant, $request->validated());

        return to_route(
            'workspaces.templates.show',
            $this->workspaceRouteParameters(['template' => $template]),
        );
    }

    public function show(
        Tenant $tenant,
        QuestionnaireTemplate $template,
        FetchQuestionnaireTemplateShowQuery $getQuestionnaireTemplateShowData,
    ): Response {
        $this->authorize('view', $template);

        return Inertia::render('workspaces/questionnaires/show', [
            'template' => $getQuestionnaireTemplateShowData->handle($template)->toArray(),
            'categories' => collect(QuestionnaireTemplateCategory::cases())
                ->map(fn (QuestionnaireTemplateCategory $category): array => [
                    'value' => $category->value,
                    'label' => $category->label(),
                ]),
            'can_manage' => request()->user()?->can('update', $template) ?? false,
            'can_copy' => request()->user()?->can('copy', $template) ?? false,
        ]);
    }

    public function update(
        Tenant $tenant,
        UpdateQuestionnaireTemplateRequest $request,
        QuestionnaireTemplate $template,
        UpdateQuestionnaireTemplateAction $updateQuestionnaireTemplate,
    ): RedirectResponse {
        $updateQuestionnaireTemplate->handle($template, $request->validated());

        return to_route(
            'workspaces.templates.show',
            $this->workspaceRouteParameters(['template' => $template]),
        );
    }

    public function destroy(
        Tenant $tenant,
        QuestionnaireTemplate $template,
        DeleteQuestionnaireTemplateAction $deleteQuestionnaireTemplate,
    ): RedirectResponse {
        $this->authorize('delete', $template);

        $deleteQuestionnaireTemplate->handle($template);

        return to_route(
            'workspaces.templates.index',
            $this->workspaceRouteParameters(),
        );
    }

    public function copy(
        Tenant $tenant,
        QuestionnaireTemplate $template,
        CopyQuestionnaireTemplateAction $copyQuestionnaireTemplate,
    ): RedirectResponse {
        $this->authorize('copy', $template);

        $copy = $copyQuestionnaireTemplate->handle($template);

        return to_route(
            'workspaces.templates.show',
            $this->workspaceRouteParameters(['template' => $copy]),
        );
    }
}
