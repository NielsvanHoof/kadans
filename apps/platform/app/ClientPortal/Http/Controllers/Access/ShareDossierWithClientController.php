<?php

declare(strict_types=1);

namespace App\ClientPortal\Http\Controllers\Access;

use App\Accounts\Http\Concerns\ResolvesCurrentUser;
use App\ClientPortal\Actions\Access\ShareDossierWithClientAction;
use App\ClientPortal\Models\ClientAccessGrant;
use App\Dossiers\Models\Dossier;
use App\Shared\Http\Controllers\Controller;
use App\Shared\Http\Toast;
use App\Tenancy\Models\Tenant;
use Illuminate\Http\RedirectResponse;

/**
 * Put a dossier in front of the client without minting a link.
 *
 * Authorised by the same pair as issuing a link — sharing and granting are one
 * capability, so this adds no permission and no role-matrix change.
 */
final class ShareDossierWithClientController extends Controller
{
    use ResolvesCurrentUser;

    public function store(
        Tenant $tenant,
        Dossier $dossier,
        ShareDossierWithClientAction $shareDossierWithClient,
    ): RedirectResponse {
        $this->authorize('view', $dossier);
        $this->authorize('create', ClientAccessGrant::class);

        $shareDossierWithClient->handle($dossier, $this->currentUser());

        Toast::success(__('Shared with the client. It now appears on their portal link.'));

        return to_route(
            'workspaces.dossiers.show',
            $this->workspaceRouteParameters(['dossier' => $dossier]),
        );
    }
}
