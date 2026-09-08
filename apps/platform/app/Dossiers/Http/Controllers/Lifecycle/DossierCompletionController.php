<?php

declare(strict_types=1);

namespace App\Dossiers\Http\Controllers;

use App\Accounts\Http\Concerns\ResolvesCurrentUser;
use App\Dossiers\Actions\CompleteDossierAction;
use App\Dossiers\Http\Requests\CompleteDossierRequest;
use App\Dossiers\Models\Dossier;
use App\Shared\Http\Controllers\Controller;
use App\Shared\Http\Toast;
use App\Tenancy\Models\Tenant;
use Illuminate\Http\RedirectResponse;

final class DossierCompletionController extends Controller
{
    use ResolvesCurrentUser;

    public function store(
        Tenant $tenant,
        CompleteDossierRequest $request,
        Dossier $dossier,
        CompleteDossierAction $completeDossier,
    ): RedirectResponse {
        $user = $this->currentUser();

        $completeDossier->handle($dossier, $user);

        Toast::success(__('Dossier completed.'));

        return to_route(
            'workspaces.dossiers.show',
            $this->workspaceRouteParameters(['dossier' => $dossier]),
        );
    }
}
