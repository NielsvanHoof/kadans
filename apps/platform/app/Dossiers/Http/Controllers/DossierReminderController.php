<?php

declare(strict_types=1);

namespace App\Dossiers\Http\Controllers;

use App\Accounts\Models\User;
use App\Dossiers\Actions\SendDossierReminderAction;
use App\Dossiers\Enums\DossierReminderSource;
use App\Dossiers\Models\Dossier;
use App\Shared\Http\Controllers\Controller;
use App\Tenancy\Models\Tenant;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

final class DossierReminderController extends Controller
{
    public function store(
        Tenant $tenant,
        Dossier $dossier,
        SendDossierReminderAction $sendDossierReminder,
    ): RedirectResponse {
        $this->authorize('sendReminder', $dossier);

        $user = request()->user();

        abort_unless($user instanceof User, Response::HTTP_FORBIDDEN);

        $sendDossierReminder->handle(
            $dossier,
            $user,
            DossierReminderSource::Manual,
            $user,
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Client reminder queued with a fresh secure link.'),
        ]);

        return to_route(
            'workspaces.dossiers.show',
            $this->workspaceRouteParameters(['dossier' => $dossier]),
        );
    }
}
