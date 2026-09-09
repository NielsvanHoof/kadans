<?php

declare(strict_types=1);

namespace App\ClientPortal\Http\Controllers\Access;

use App\Accounts\Http\Concerns\ResolvesCurrentUser;
use App\ClientPortal\Actions\Access\IssueClientPortalAccessAction;
use App\ClientPortal\Actions\Access\RevokeClientPortalAccessAction;
use App\ClientPortal\Http\Requests\Access\StoreClientAccessGrantRequest;
use App\ClientPortal\Models\ClientAccessGrant;
use App\Dossiers\Models\Dossier;
use App\Shared\Http\Controllers\Controller;
use App\Shared\Http\Toast;
use App\Tenancy\Models\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\URL;

final class ClientAccessGrantController extends Controller
{
    use ResolvesCurrentUser;

    public function store(
        Tenant $tenant,
        StoreClientAccessGrantRequest $request,
        Dossier $dossier,
        IssueClientPortalAccessAction $issueClientPortalAccess,
    ): RedirectResponse {
        $this->authorize('view', $dossier);

        $user = $this->currentUser();

        $sendInvite = (bool) ($request->validated('send_invite') ?? true);

        $result = $issueClientPortalAccess->handle(
            $dossier,
            $user,
            (int) ($request->validated('expires_in_days') ?? 7),
            $sendInvite,
        );

        $plainTextToken = $result['plain_text_token'];
        $portalUrl = URL::route('portal.exchange', ['token' => $plainTextToken]);

        // Flash plaintext once — the DB only stores the hash.
        $request->session()->flash('access_grant_token', $plainTextToken);
        $request->session()->flash('access_grant_portal_url', $portalUrl);

        if ($sendInvite) {
            Toast::success(__('Invitation sent. Copy the portal link now — you won\'t see it again.'));
        } else {
            Toast::success(__('Client access link created. Copy it now — it will not be shown again.'));
        }

        return to_route(
            'workspaces.dossiers.show',
            $this->workspaceRouteParameters(['dossier' => $dossier]),
        );
    }

    public function destroy(
        Tenant $tenant,
        ClientAccessGrant $grant,
        RevokeClientPortalAccessAction $revokeClientPortalAccess,
    ): RedirectResponse {
        $this->authorize('revoke', $grant);

        $user = $this->currentUser();

        $revokeClientPortalAccess->handle($grant, $user);

        Toast::success(__('Portal link revoked.'));

        return to_route(
            'workspaces.dossiers.show',
            $this->workspaceRouteParameters(['dossier' => $grant->dossier_id]),
        );
    }
}
