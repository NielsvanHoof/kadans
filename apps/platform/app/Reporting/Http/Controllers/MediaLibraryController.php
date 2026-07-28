<?php

declare(strict_types=1);

namespace App\Reporting\Http\Controllers;

use App\Dossiers\Models\DocumentRequest;
use App\Reporting\Queries\FetchMediaLibraryDocumentsQuery;
use App\Shared\Http\Controllers\Controller;
use App\Tenancy\Enums\Permission;
use App\Tenancy\Models\Tenant;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class MediaLibraryController extends Controller
{
    /**
     * Cross-dossier list of file document requests (pending and submitted).
     */
    public function index(
        Tenant $tenant,
        Request $request,
        FetchMediaLibraryDocumentsQuery $fetchMediaLibraryDocuments,
    ): Response {
        $this->authorize('viewAny', DocumentRequest::class);

        $user = $request->user();
        $canDownload = $user !== null && $user->hasTenantPermission(Permission::DownloadDocuments);

        return Inertia::render('workspaces/media/index', [
            'can_download' => $canDownload,
            'documents' => $fetchMediaLibraryDocuments->handle(),
            ...$fetchMediaLibraryDocuments->indexQueryProps(),
        ]);
    }
}
