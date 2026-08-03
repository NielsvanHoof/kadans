<?php

declare(strict_types=1);

namespace App\Dossiers\Http\Controllers;

use App\Accounts\Http\Concerns\ResolvesCurrentUser;
use App\Dossiers\Actions\SendDossierReminderAction;
use App\Dossiers\Enums\DossierReminderSource;
use App\Dossiers\Models\Dossier;
use App\Shared\Http\Controllers\Controller;
use App\Shared\Http\Toast;
use App\Tenancy\Models\Tenant;
use Illuminate\Http\RedirectResponse;

final class DossierReminderController extends Controller
{
    use ResolvesCurrentUser;

    public function store(
        Tenant $tenant,
        Dossier $dossier,
        SendDossierReminderAction $sendDossierReminder,
    ): RedirectResponse {
        $this->authorize('sendReminder', $dossier);

        $user = $this->currentUser();

        $sendDossierReminder->handle(
            $dossier,
            $user,
            DossierReminderSource::Manual,
            $user,
        );

        Toast::success(__('Client reminder queued with a fresh secure link.'));

        return to_route(
            'workspaces.dossiers.show',
            $this->workspaceRouteParameters(['dossier' => $dossier]),
        );
    }
}
