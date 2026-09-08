<?php

declare(strict_types=1);

namespace App\ClientPortal\Http\Controllers\Session;

use App\ClientPortal\Enums\PortalSessionEndReason;
use App\ClientPortal\Http\Middleware\ResolveClientPortalGrant;
use App\Shared\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

use function is_string;

/**
 * Explains a portal session that stopped working.
 *
 * Deliberately outside the ResolveClientPortalGrant group: it has to render
 * once there is no usable grant left, and running the resolver here would loop.
 *
 * This is the one place the portal breaks its own "every denial is a 404" rule,
 * and it is safe because the resolver only redirects here off a signed-cookie
 * session that a successful token exchange wrote. Someone typing the URL in
 * cold gets the timed-out copy, which tells them nothing they did not bring.
 */
final class ClientPortalEndedController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $reason = $request->session()->get(ResolveClientPortalGrant::SESSION_END_REASON);
        $firmName = $request->session()->get(ResolveClientPortalGrant::SESSION_FIRM_NAME);

        return Inertia::render('portal/ended', [
            'reason' => (is_string($reason) ? PortalSessionEndReason::tryFrom($reason) : null)
                ?? PortalSessionEndReason::TimedOut,
            'firm_name' => is_string($firmName) ? $firmName : null,
        ]);
    }
}
