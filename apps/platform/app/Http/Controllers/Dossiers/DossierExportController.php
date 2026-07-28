<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dossiers;

use App\Actions\Audit\LogAuditActivityAction;
use App\Actions\Dossiers\CreateDossierExportAction;
use App\Enums\AuditEvent;
use App\Http\Controllers\Controller;
use App\Jobs\Dossiers\GenerateDossierExportJob;
use App\Models\Dossier;
use App\Models\DossierExport;
use App\Models\Pipeline;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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
    ): StreamedResponse {
        $this->authorize('export', $dossier);

        abort_unless($export->dossier_id === $dossier->id && $export->isDownloadable(), HttpResponse::HTTP_NOT_FOUND);

        $logAuditActivity->handle(
            AuditEvent::DossierExportDownloaded,
            $export,
            ['dossier_id' => $dossier->id],
        );

        $disk = $export->disk;
        $path = $export->path;

        if ($disk === null || $path === null) {
            abort(HttpResponse::HTTP_NOT_FOUND);
        }

        return Storage::disk($disk)->download(
            $path,
            (Str::slug($dossier->title) !== '' ? Str::slug($dossier->title) : 'dossier').'-export.zip',
            ['Content-Type' => 'application/zip'],
        );
    }
}
