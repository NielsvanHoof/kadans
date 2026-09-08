<?php

declare(strict_types=1);

namespace App\Dossiers\Http\Controllers\Lifecycle;

use App\Dossiers\Actions\Lifecycle\RollOverDossierAction;
use App\Dossiers\Http\Requests\Lifecycle\RollOverDossierRequest;
use App\Dossiers\Models\Dossier;
use App\Shared\Http\Controllers\Controller;
use App\Shared\Http\Toast;
use App\Tenancy\Models\Tenant;
use Illuminate\Http\RedirectResponse;

final class DossierRollOverController extends Controller
{
    public function store(
        Tenant $tenant,
        Dossier $dossier,
        RollOverDossierRequest $request,
        RollOverDossierAction $rollOverDossier,
    ): RedirectResponse {
        $rolledOver = $rollOverDossier->handle($dossier, $request->toData());

        Toast::success(__('New period started. Check the answers carried over before you invite the client.'));

        return to_route(
            'workspaces.dossiers.show',
            $this->workspaceRouteParameters(['dossier' => $rolledOver]),
        );
    }
}
