<?php

declare(strict_types=1);

namespace App\ClientPortal\Http\Controllers\Session;

use App\ClientPortal\Actions\Access\ResolveClientAccessGrantFromTokenAction;
use App\ClientPortal\Actions\Session\RegisterClientPortalDeviceAction;
use App\ClientPortal\Http\Middleware\ResolveClientPortalGrant;
use App\ClientPortal\Models\ClientAccessGrant;
use App\ClientPortal\Support\Session\PortalDeviceFingerprint;
use App\ClientPortal\Support\Session\PortalSessionWindow;
use App\Shared\Http\Controllers\Controller;
use App\Tenancy\Models\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ClientPortalSessionController extends Controller
{
    public function __invoke(
        Request $request,
        string $token,
        ResolveClientAccessGrantFromTokenAction $resolveClientAccessGrantFromToken,
        RegisterClientPortalDeviceAction $registerClientPortalDevice,
    ): RedirectResponse {
        $grant = $resolveClientAccessGrantFromToken->handle($token);

        abort_unless($grant instanceof ClientAccessGrant, Response::HTTP_NOT_FOUND);

        // Redemption is the security event, so the device is checked here rather
        // than per request: once a session exists the device cannot change.
        $outcome = $registerClientPortalDevice->handle(
            $grant,
            PortalDeviceFingerprint::fromRequest($request),
        );

        // Concealed as a 404 like every other portal denial, so a probe cannot
        // tell a blocked device from a token that never existed.
        abort_if($outcome->isBlocked(), Response::HTTP_NOT_FOUND);

        // Both deadlines are stamped here. Only the idle one moves after this,
        // and only in ResolveClientPortalGrant.
        $window = PortalSessionWindow::open($grant);

        $request->session()->regenerate(destroy: true);
        $request->session()->put([
            ResolveClientPortalGrant::SESSION_GRANT_ID => $grant->getKey(),
            ResolveClientPortalGrant::SESSION_EXPIRES_AT => $window->idleExpiresAt->getTimestamp(),
            ResolveClientPortalGrant::SESSION_ABSOLUTE_EXPIRES_AT => $window->absoluteExpiresAt->getTimestamp(),
            // Kept through session teardown so the ended page stays branded for
            // a client who has no idea why the page stopped working.
            ResolveClientPortalGrant::SESSION_FIRM_NAME => $this->firmName($grant),
        ]);

        // StartSession stores successful GET URLs after the controller returns.
        // Prevent the redeemed bearer URL from being copied to `_previous.url`.
        $request->headers->set('X-Requested-With', 'XMLHttpRequest');

        return to_route('portal.show');
    }

    /**
     * The tenant's public display name. No tenant is current on this route —
     * the exchange runs before ResolveClientPortalGrant — and Tenant is not
     * itself tenant-scoped, so this is a plain lookup.
     */
    private function firmName(ClientAccessGrant $grant): ?string
    {
        $tenant = Tenant::query()->find($grant->tenant_id);

        return $tenant instanceof Tenant ? $tenant->name : null;
    }
}
