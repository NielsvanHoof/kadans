<?php

declare(strict_types=1);

namespace App\Shared\Http\Middleware;

use App\Shared\Observability\ObservabilityContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stamp every web request with a correlation id and its route name.
 *
 * Runs in the web group rather than the global stack so `$request->route()` is
 * already resolved — global middleware execute before the router dispatches.
 *
 * Tenant and user are not set here: they are stamped by listeners at the moment
 * they actually become known (MadeTenantCurrentEvent, Authenticated), which
 * also covers console and queue work that never passes through this middleware.
 */
final readonly class TrackRequestContext
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = ObservabilityContext::rememberRequestId(
            $request->headers->get(ObservabilityContext::RequestIdHeader),
        );

        $route = $request->route();

        ObservabilityContext::rememberRoute($route instanceof Route ? $route->getName() : null);

        $response = $next($request);

        $response->headers->set(ObservabilityContext::RequestIdHeader, $requestId);

        return $response;
    }
}
