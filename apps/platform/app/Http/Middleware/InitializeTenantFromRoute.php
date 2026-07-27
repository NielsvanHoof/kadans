<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

use function is_string;

final class InitializeTenantFromRoute
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $tenantParameter = $request->route('tenant');
        $user = $request->user();

        if (! $user instanceof User) {
            abort(Response::HTTP_NOT_FOUND);
        }

        $tenantSlug = $tenantParameter instanceof Tenant
            ? $tenantParameter->slug
            : (is_string($tenantParameter) ? $tenantParameter : null);

        if ($tenantSlug === null) {
            abort(Response::HTTP_NOT_FOUND);
        }

        $tenant = Tenant::query()->where('slug', $tenantSlug)->first();

        if (! $tenant instanceof Tenant) {
            abort(Response::HTTP_NOT_FOUND);
        }

        $user->loadMissing('tenantMemberships');

        // Membership or active platform support session for this firm.
        if (! $user->canAccessTenant($tenant)) {
            abort(Response::HTTP_NOT_FOUND);
        }

        $tenant->makeCurrent();
        $request->route()?->setParameter('tenant', $tenant);
        $request->session()->put('active_tenant_id', $tenant->id);

        return $next($request);
    }
}
