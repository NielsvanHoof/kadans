<?php

declare(strict_types=1);

namespace App\Dossiers\Http\Controllers;

use App\Accounts\Http\Concerns\ResolvesCurrentUser;
use App\Audit\Actions\LogAuditActivityAction;
use App\Audit\Enums\AuditEvent;
use App\Dossiers\Actions\CreateDossierExportAction;
use App\Dossiers\Actions\DownloadDossierExportAction;
use App\Dossiers\Data\DossierExportData;
use App\Dossiers\Models\Dossier;
use App\Dossiers\Models\DossierExport;
use App\Shared\Http\Controllers\Controller;
use App\Shared\Http\Toast;
use App\Tenancy\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class DossierExportController extends Controller
{
    use ResolvesCurrentUser;

    public function store(
        Tenant $tenant,
        Dossier $dossier,
        CreateDossierExportAction $createDossierExport,
    ): RedirectResponse {
        $this->authorize('export', $dossier);

        $createDossierExport->handle($dossier, $this->currentUser());

        Toast::success(__('Dossier export queued.'));

        return to_route(
            'workspaces.dossiers.show',
            $this->workspaceRouteParameters(['dossier' => $dossier]),
        );
    }

    /**
     * Narrow export status for the show-page island (Echo → JSON merge).
     */
    public function show(
        Tenant $tenant,
        Dossier $dossier,
        DossierExport $export,
    ): JsonResponse {
        $this->authorize('export', $dossier);

        abort_unless($export->dossier_id === $dossier->id, HttpResponse::HTTP_NOT_FOUND);

        $export->loadMissing(['pipeline.steps', 'tenant']);

        $canDownload = request()->user()?->can('export', $dossier) ?? false;

        return response()->json([
            'export' => DossierExportData::fromModel($export, $canDownload)->toArray(),
        ]);
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
