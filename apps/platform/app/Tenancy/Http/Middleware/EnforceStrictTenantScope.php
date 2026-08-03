<?php

declare(strict_types=1);

namespace App\Tenancy\Http\Middleware;

use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Opt into fail-fast BelongsToTenant queries for staff workspace requests.
 * Tenant must already be current (InitializeTenantFromRoute runs first).
 */
final readonly class EnforceStrictTenantScope
{
    public function __construct(
        private TenantContext $tenantContext,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $this->tenantContext->enableStrict();

        return $next($request);
    }
}
