<?php

declare(strict_types=1);

namespace App\Reporting\Http\Controllers;

use App\Accounts\Http\Concerns\ResolvesCurrentUser;
use App\Audit\Actions\LogAuditActivityAction;
use App\Audit\Enums\AuditEvent;
use App\Dossiers\Actions\DownloadDossierExportAction;
use App\Dossiers\Models\DossierExport;
use App\Pipelines\Actions\DeletePipelineAction;
use App\Pipelines\Actions\RetryPipelineAction;
use App\Pipelines\Models\Pipeline;
use App\Reporting\Queries\FetchPipelineIndexQuery;
use App\Reporting\Queries\FetchPipelineShowQuery;
use App\Shared\Http\Controllers\Controller;
use App\Shared\Http\Toast;
use App\Tenancy\Models\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class PipelineController extends Controller
{
    use ResolvesCurrentUser;

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
                'can_download' => $request->user()?->can('download', $pipeline) ?? false,
                'can_retry' => $request->user()?->can('retry', $pipeline) ?? false,
            ],
        );
    }

    /**
     * Streams the pipeline payload. Only export pipelines carry a downloadable
     * archive; the policy denies every other pipeline type.
     */
    public function download(
        Tenant $tenant,
        Pipeline $pipeline,
        LogAuditActivityAction $logAuditActivity,
        DownloadDossierExportAction $downloadDossierExport,
    ): StreamedResponse {
        $this->authorize('download', $pipeline);

        $pipeline->loadMissing('pipelineable');
        $export = $pipeline->pipelineable;

        abort_unless($export instanceof DossierExport, HttpResponse::HTTP_NOT_FOUND);

        $logAuditActivity->handle(
            AuditEvent::DossierExportDownloaded,
            $export,
            [
                'dossier_id' => $export->dossier_id,
                'pipeline_id' => $pipeline->id,
            ],
        );

        return $downloadDossierExport->handle($export);
    }

    /**
     * Re-queue a failed or stuck run. The user stays on the detail page so they
     * can watch the reopened run progress over the pipeline.updated broadcast.
     */
    public function retry(
        Tenant $tenant,
        Pipeline $pipeline,
        RetryPipelineAction $retryPipeline,
    ): RedirectResponse {
        $this->authorize('retry', $pipeline);

        $retryPipeline->handle($pipeline, $this->currentUser());

        Toast::success(__('Pipeline retry queued.'));

        return to_route(
            'workspaces.pipelines.show',
            $this->workspaceRouteParameters(['pipeline' => $pipeline->id]),
        );
    }

    public function destroy(
        Tenant $tenant,
        Pipeline $pipeline,
        DeletePipelineAction $deletePipeline,
    ): RedirectResponse {
        $this->authorize('delete', $pipeline);

        $user = $this->currentUser();

        $deletePipeline->handle($pipeline, $user);

        Toast::success(__('Pipeline deleted.'));

        return to_route('workspaces.pipelines.index', $this->workspaceRouteParameters());
    }
}
