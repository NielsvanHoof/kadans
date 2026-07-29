<?php

declare(strict_types=1);

namespace App\Dossiers\Http\Controllers;

use App\Accounts\Models\User;
use App\Audit\Actions\LogAuditActivityAction;
use App\Audit\Enums\AuditEvent;
use App\DocumentProcessing\Models\Pipeline;
use App\Dossiers\Actions\CreateDossierExportAction;
use App\Dossiers\Actions\DownloadDossierExportAction;
use App\Dossiers\Jobs\GenerateDossierExportJob;
use App\Dossiers\Models\Dossier;
use App\Dossiers\Models\DossierExport;
use App\Shared\Http\Controllers\Controller;
use App\Tenancy\Models\Tenant;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class DossierExportController extends Controller
{
    public function store(
        Tenant $tenant,
        Dossier $dossier,
        CreateDossierExportAction $createDossierExport,
    ): RedirectResponse {
        $this->authorize('export', $dossier);
        $user = request()->user();

        if (! $user instanceof User) {
            abort(HttpResponse::HTTP_FORBIDDEN);
        }

        $export = $createDossierExport->handle($dossier, $user);
        $pipeline = $export->pipeline;

        if (! $pipeline instanceof Pipeline) {
            abort(HttpResponse::HTTP_INTERNAL_SERVER_ERROR);
        }

        GenerateDossierExportJob::dispatch($pipeline->id)->afterCommit();

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Dossier export queued.'),
        ]);

        return to_route(
            'workspaces.dossiers.show',
            $this->workspaceRouteParameters(['dossier' => $dossier]),
        );
    }

    public function download(
        Tenant $tenant,
        Dossier $dossier,
        DossierExport $export,
        LogAuditActivityAction $logAuditActivity,
        DownloadDossierExportAction $downloadDossierExport,
    ): StreamedResponse {
        $this->authorize('export', $dossier);

        abort_unless($export->dossier_id === $dossier->id && $export->isDownloadable(), HttpResponse::HTTP_NOT_FOUND);

        $logAuditActivity->handle(
            AuditEvent::DossierExportDownloaded,
            $export,
            ['dossier_id' => $dossier->id],
        );

        return $downloadDossierExport->handle($export->setRelation('dossier', $dossier));
    }
}
