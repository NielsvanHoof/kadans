<?php

declare(strict_types=1);

namespace App\ClientPortal\Http\Controllers;

use App\ClientPortal\Actions\RegisterClientPortalDeviceAction;
use App\ClientPortal\Actions\ResolveClientAccessGrantFromTokenAction;
use App\ClientPortal\Http\Middleware\ResolveClientPortalGrant;
use App\ClientPortal\Models\ClientAccessGrant;
use App\ClientPortal\Support\PortalDeviceFingerprint;
use App\Shared\Http\Controllers\Controller;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Support\TenantHostname;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

use function max;
use function min;

final class ClientPortalSessionController extends Controller
{
    public function __invoke(
        Request $request,
        string $tenant,
        string $token,
        ResolveClientAccessGrantFromTokenAction $resolveClientAccessGrantFromToken,
        RegisterClientPortalDeviceAction $registerClientPortalDevice,
    ): RedirectResponse {
        $hostname = resolve(TenantHostname::class);
        abort_unless($hostname->isValidTenantSlug($tenant), Response::HTTP_NOT_FOUND);

        $grant = $resolveClientAccessGrantFromToken->handle($token);

        abort_unless($grant instanceof ClientAccessGrant, Response::HTTP_NOT_FOUND);

        // Token must belong to the firm named by the subdomain.
        $grantTenant = Tenant::query()->find($grant->tenant_id);
        abort_unless(
            $grantTenant instanceof Tenant && $grantTenant->slug === $tenant,
            Response::HTTP_NOT_FOUND,
        );

        // Redemption is the security event, so the device is checked here rather
        // than per request: once a session exists the device cannot change.
        $outcome = $registerClientPortalDevice->handle(
            $grant,
            PortalDeviceFingerprint::fromRequest($request),
        );

        // Concealed as a 404 like every other portal denial, so a probe cannot
        // tell a blocked device from a token that never existed.
        abort_if($outcome->isBlocked(), Response::HTTP_NOT_FOUND);

        $lifetimeMinutes = max(1, min(
            60,
            (int) config('portal.session_lifetime_minutes', 15),
        ));
        $sessionExpiresAt = now()->addMinutes($lifetimeMinutes);

        if ($grant->expires_at->lessThan($sessionExpiresAt)) {
            $sessionExpiresAt = $grant->expires_at;
        }

        $request->session()->regenerate(destroy: true);
        $request->session()->put([
            ResolveClientPortalGrant::SESSION_GRANT_ID => $grant->getKey(),
            ResolveClientPortalGrant::SESSION_EXPIRES_AT => $sessionExpiresAt->getTimestamp(),
        ]);

        // StartSession stores successful GET URLs after the controller returns.
        // Prevent the redeemed bearer URL from being copied to `_previous.url`.
        $request->headers->set('X-Requested-With', 'XMLHttpRequest');

        URL::defaults(['tenant' => $grantTenant->slug]);

        return to_route('portal.show');
    }
}
