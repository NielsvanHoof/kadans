<?php

declare(strict_types=1);

namespace App\ClientPortal\Http\Controllers\Session;

use App\Shared\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;

/**
 * Lets a client who is still at their desk keep the session open.
 *
 * There is nothing to do here: passing through ResolveClientPortalGrant is what
 * slides the idle deadline forward, and reaching this method means that already
 * happened. It exists so the pre-expiry warning has something deliberate to
 * call — a background heartbeat would defeat idle expiry entirely, whereas a
 * button press is evidence of a person.
 *
 * This cannot become an unbounded keep-alive: the absolute ceiling stamped at
 * redemption is never extended, so the session still dies on schedule.
 */
final class ClientPortalSessionExtendController extends Controller
{
    public function __invoke(): RedirectResponse
    {
        return back();
    }
}
