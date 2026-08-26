<?php

declare(strict_types=1);

namespace App\Shared\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Laravel\Horizon\Horizon;
use Symfony\Component\HttpFoundation\Response;

use function is_string;

/**
 * Copy the per-request Vite CSP nonce onto Horizon's Blade assets.
 *
 * AddSecurityHeaders generates the nonce on the way in; Horizon renders
 * inside that stack and needs the same value on its script/style tags or
 * the dashboard scripts are blocked. Stored as a static on Horizon, so this
 * must run every request (Octane reuses the process).
 */
final class AddHorizonCspNonce
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $nonce = Vite::cspNonce();

        if (is_string($nonce) && $nonce !== '') {
            Horizon::cspNonce($nonce);
        }

        return $next($request);
    }
}
