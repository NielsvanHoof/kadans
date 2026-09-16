<?php

declare(strict_types=1);

namespace App\ClientPortal\Http\Controllers\Access;

use App\Accounts\Http\Concerns\ResolvesCurrentUser;
use App\ClientPortal\Actions\Access\IssueClientPortalLinkAction;
use App\ClientPortal\Http\Requests\Access\StoreClientPortalLinkRequest;
use App\Clients\Models\Client;
use App\Shared\Http\Controllers\Controller;
use App\Shared\Http\Toast;
use App\Tenancy\Models\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\URL;

/**
 * One standing link per client, issued from the client page.
 *
 * Revoking goes through ClientAccessGrantController::destroy, which both scopes
 * share — the grant is the same row and revoking it is the same act.
 */
final class ClientPortalLinkController extends Controller
{
    use ResolvesCurrentUser;

    public function store(
        Tenant $tenant,
        StoreClientPortalLinkRequest $request,
        Client $client,
        IssueClientPortalLinkAction $issueClientPortalLink,
    ): RedirectResponse {
        $this->authorize('view', $client);

        $sendInvite = (bool) ($request->validated('send_invite') ?? true);
        $expiresInDays = $request->validated('expires_in_days');

        $result = $issueClientPortalLink->handle(
            $client,
            $this->currentUser(),
            $expiresInDays === null ? null : (int) $expiresInDays,
            $sendInvite,
        );

        $plainTextToken = $result['plain_text_token'];

        // Flash plaintext once — the database only ever stores the hash.
        $request->session()->flash('access_grant_token', $plainTextToken);
        $request->session()->flash(
            'access_grant_portal_url',
            URL::route('portal.exchange', ['token' => $plainTextToken]),
        );

        Toast::success($sendInvite
            ? __('Invitation sent. Copy the link now — you won\'t see it again.')
            : __('Client link created. Copy it now — it will not be shown again.'));

        return to_route(
            'workspaces.clients.show',
            $this->workspaceRouteParameters(['client' => $client]),
        );
    }
}
