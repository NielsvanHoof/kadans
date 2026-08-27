<?php

declare(strict_types=1);

namespace App\Shared\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Laravel\Telescope\Telescope;
use Symfony\Component\HttpFoundation\Response;

use function is_string;

/**
 * Copy the per-request Vite CSP nonce onto Telescope's Blade assets.
 *
 * AddSecurityHeaders generates the nonce on the way in; Telescope renders
 * inside that stack and needs the same value on its script/style tags or
 * the dashboard boot script is blocked. Stored as a static on Telescope, so
 * this must run every request (Octane reuses the process).
 */
final class AddTelescopeCspNonce
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $nonce = Vite::cspNonce();

        if (is_string($nonce) && $nonce !== '') {
            Telescope::cspNonce($nonce);
        }

        return $next($request);
    }
}
