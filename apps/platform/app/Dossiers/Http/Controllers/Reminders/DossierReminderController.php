<?php

declare(strict_types=1);

namespace App\Dossiers\Http\Controllers\Reminders;

use App\Accounts\Http\Concerns\ResolvesCurrentUser;
use App\Dossiers\Actions\Reminders\SendDossierReminderAction;
use App\Dossiers\Enums\DossierReminderSource;
use App\Dossiers\Http\Requests\Reminders\StoreDossierReminderRequest;
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
        StoreDossierReminderRequest $request,
        SendDossierReminderAction $sendDossierReminder,
    ): RedirectResponse {
        $user = $this->currentUser();

        $sendDossierReminder->handle(
            $dossier,
            $user,
            DossierReminderSource::Manual,
            $user,
            $request->validated('message'),
        );

        Toast::success(__('Client reminder sent with a fresh secure link.'));

        return to_route(
            'workspaces.dossiers.show',
            $this->workspaceRouteParameters(['dossier' => $dossier]),
        );
    }
}
