<?php

declare(strict_types=1);

namespace App\Tenancy\Http\Middleware;

use App\Accounts\Models\User;
use App\Tenancy\Contracts\SupportSessionState;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Support\TenantHostname;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

use function is_string;

/**
 * Resolve the current tenant from the {tenant} subdomain route parameter.
 */
final readonly class InitializeTenantFromRoute
{
    public function __construct(
        private SupportSessionState $supportSession,
        private TenantHostname $tenantHostname,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $tenantParameter = $request->route('tenant');
        $user = $request->user();

        abort_unless($user instanceof User, Response::HTTP_NOT_FOUND);

        $tenantSlug = $tenantParameter instanceof Tenant
            ? $tenantParameter->slug
            : (is_string($tenantParameter) ? $tenantParameter : null);

        abort_if($tenantSlug === null, Response::HTTP_NOT_FOUND);
        abort_unless($this->tenantHostname->isValidTenantSlug($tenantSlug), Response::HTTP_NOT_FOUND);

        $tenant = Tenant::query()->where('slug', $tenantSlug)->first();

        abort_unless($tenant instanceof Tenant, Response::HTTP_NOT_FOUND);

        $user->loadMissing('tenantMemberships');

        $this->supportSession->clearIfExpired($user);

        // Membership or active platform support session for this firm.
        abort_unless($user->canAccessTenant($tenant), Response::HTTP_NOT_FOUND);

        $tenant->makeCurrent();
        $request->route()?->setParameter('tenant', $tenant);
        $request->session()->put('active_tenant_id', $tenant->id);
        URL::defaults(['tenant' => $tenant->slug]);

        return $next($request);
    }
}
