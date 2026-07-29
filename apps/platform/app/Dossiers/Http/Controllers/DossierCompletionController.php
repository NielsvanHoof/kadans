<?php

declare(strict_types=1);

namespace App\Dossiers\Http\Controllers;

use App\Accounts\Models\User;
use App\Dossiers\Actions\CompleteDossierAction;
use App\Dossiers\Http\Requests\CompleteDossierRequest;
use App\Dossiers\Models\Dossier;
use App\Shared\Http\Controllers\Controller;
use App\Tenancy\Models\Tenant;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

final class DossierCompletionController extends Controller
{
    public function store(
        Tenant $tenant,
        CompleteDossierRequest $request,
        Dossier $dossier,
        CompleteDossierAction $completeDossier,
    ): RedirectResponse {
        $user = $request->user();

        abort_unless($user instanceof User, Response::HTTP_FORBIDDEN);

        $completeDossier->handle($dossier, $user);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Dossier completed.'),
        ]);

        return to_route(
            'workspaces.dossiers.show',
            $this->workspaceRouteParameters(['dossier' => $dossier]),
        );
    }
}
