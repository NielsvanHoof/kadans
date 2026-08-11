<?php

declare(strict_types=1);

namespace App\ClientPortal\Http\Middleware;

use App\ClientPortal\Models\ClientAccessGrant;
use App\Tenancy\Models\Tenant;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

use function is_int;
use function is_string;

/**
 * Resolves a short-lived portal session and makes the grant's tenant current
 * before route model binding runs (see bootstrap/app.php priority list).
 */
final readonly class ResolveClientPortalGrant
{
    public const string REQUEST_ATTRIBUTE = 'client_access_grant';

    public const string SESSION_EXPIRES_AT = 'portal.expires_at';

    public const string SESSION_GRANT_ID = 'portal.grant_id';

    public function __construct(
        private TenantContext $tenantContext,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Only token-free portal session routes use this resolver.
        if (! $request->routeIs('portal.*')) {
            return $next($request);
        }

        $grantId = $request->session()->get(self::SESSION_GRANT_ID);
        $expiresAt = $request->session()->get(self::SESSION_EXPIRES_AT);

        if (! is_int($grantId) || ! is_int($expiresAt) || $expiresAt <= now()->getTimestamp()) {
            $this->clearPortalSession($request);
            abort(Response::HTTP_NOT_FOUND);
        }

        $grant = ClientAccessGrant::queryWithoutTenantScope()
            ->whereKey($grantId)
            ->where('revoked_at', null)
            ->where('expires_at', '>', now())
            ->first();

        if (! $grant instanceof ClientAccessGrant) {
            $this->clearPortalSession($request);
            abort(Response::HTTP_NOT_FOUND);
        }

        $tenant = Tenant::query()->find($grant->tenant_id);

        abort_unless($tenant instanceof Tenant, Response::HTTP_NOT_FOUND);

        // Host slug must match the grant's firm — blocks cross-tenant token reuse.
        $hostSlug = $request->route('tenant');
        abort_unless(is_string($hostSlug) && $hostSlug === $tenant->slug, Response::HTTP_NOT_FOUND);

        // Tenant must be current so BelongsToTenant scopes work for dossier queries.
        $tenant->makeCurrent();
        // Fail fast if a later query runs without the portal tenant.
        $this->tenantContext->enableStrict();
        URL::defaults(['tenant' => $tenant->slug]);

        $request->attributes->set(self::REQUEST_ATTRIBUTE, $grant);

        return $next($request);
    }

    private function clearPortalSession(Request $request): void
    {
        $request->session()->forget([
            self::SESSION_GRANT_ID,
            self::SESSION_EXPIRES_AT,
        ]);
    }
}
