<?php

declare(strict_types=1);

namespace App\Reporting\Http\Controllers;

use App\Accounts\Models\User;
use App\DocumentProcessing\Actions\Pipelines\DeletePipelineAction;
use App\DocumentProcessing\Models\Pipeline;
use App\Reporting\Queries\FetchPipelineIndexQuery;
use App\Reporting\Queries\FetchPipelineShowQuery;
use App\Shared\Http\Controllers\Controller;
use App\Tenancy\Models\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

final class PipelineController extends Controller
{
    public function index(
        Tenant $tenant,
        FetchPipelineIndexQuery $fetchPipelineIndex,
    ): Response {
        $this->authorize('viewAny', Pipeline::class);

        return Inertia::render('workspaces/pipelines/index', [
            'pipelines' => $fetchPipelineIndex->handle(),
            ...$fetchPipelineIndex->indexQueryProps(),
        ]);
    }

    public function show(
        Tenant $tenant,
        Request $request,
        Pipeline $pipeline,
        FetchPipelineShowQuery $fetchPipelineShow,
    ): Response {
        $this->authorize('view', $pipeline);

        return Inertia::render(
            'workspaces/pipelines/show',
            [
                ...$fetchPipelineShow->handle($pipeline),
                'can_delete' => $request->user()?->can('delete', $pipeline) ?? false,
            ],
        );
    }

    public function destroy(
        Tenant $tenant,
        Request $request,
        Pipeline $pipeline,
        DeletePipelineAction $deletePipeline,
    ): RedirectResponse {
        $this->authorize('delete', $pipeline);

        $user = $request->user();

        if (! $user instanceof User) {
            abort(HttpResponse::HTTP_FORBIDDEN);
        }

        $deletePipeline->handle($pipeline, $user);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Pipeline deleted.'),
        ]);

        return to_route('workspaces.pipelines.index', $this->workspaceRouteParameters());
    }
}
