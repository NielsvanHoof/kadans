<?php

declare(strict_types=1);

namespace App\ClientPortal\Http\Middleware;

use App\ClientPortal\Enums\PortalSessionEndReason;
use App\ClientPortal\Models\ClientAccessGrant;
use App\ClientPortal\Support\Session\PortalSessionWindow;
use App\Tenancy\Models\Tenant;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

use function is_int;

/**
 * Resolves a short-lived portal session and makes the grant's tenant current
 * before route model binding runs (see bootstrap/app.php priority list).
 *
 * Also slides the session forward: every request that resolves a live grant
 * pushes the idle deadline out, bounded by the absolute ceiling stamped at
 * redemption. Without that an active client filling in a long questionnaire was
 * signed out mid-form.
 *
 * Do NOT add Timebox here — see the ClientPortal rules. SESSION_GRANT_ID is only
 * ever written after a successful token exchange and sits behind a signed
 * cookie, so there is no attacker-iterable oracle to conceal.
 */
final readonly class ResolveClientPortalGrant
{
    public const string REQUEST_ATTRIBUTE = 'client_access_grant';

    public const string REQUEST_SESSION_WINDOW = 'client_portal_session_window';

    /** Idle deadline. Rewritten on every request. */
    public const string SESSION_EXPIRES_AT = 'portal.expires_at';

    /** Ceiling stamped once at redemption. Never rewritten. */
    public const string SESSION_ABSOLUTE_EXPIRES_AT = 'portal.absolute_expires_at';

    public const string SESSION_FIRM_NAME = 'portal.firm_name';

    public const string SESSION_GRANT_ID = 'portal.grant_id';

    /** Survives teardown so the ended page can still name the firm. */
    public const string SESSION_END_REASON = 'portal.end_reason';

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
        $idleExpiresAt = $request->session()->get(self::SESSION_EXPIRES_AT);
        $absoluteExpiresAt = $request->session()->get(self::SESSION_ABSOLUTE_EXPIRES_AT);

        // No portal session at all — indistinguishable from a probe, so 404.
        // A session predating the sliding window has no ceiling and is treated
        // the same way: the client re-opens their link. Simpler than a backfill.
        if (! is_int($grantId) || ! is_int($idleExpiresAt) || ! is_int($absoluteExpiresAt)) {
            $this->clearPortalSession($request);
            abort(Response::HTTP_NOT_FOUND);
        }

        $window = PortalSessionWindow::fromTimestamps($idleExpiresAt, $absoluteExpiresAt);

        if ($window->hasEnded()) {
            return $this->endSession($request, PortalSessionEndReason::TimedOut);
        }

        $grant = ClientAccessGrant::queryWithoutTenantScope()
            ->whereKey($grantId)
            ->first();

        // A grant id in a signed session that resolves to nothing means the row
        // is gone (pruned or hard-deleted), which is not a client-facing state.
        if (! $grant instanceof ClientAccessGrant) {
            $this->clearPortalSession($request);
            abort(Response::HTTP_NOT_FOUND);
        }

        // Revoked or expired: the client held a real session, so say so rather
        // than 404ing them. Re-opening the emailed link will not help here.
        if (! $grant->isValid()) {
            return $this->endSession($request, PortalSessionEndReason::AccessEnded);
        }

        $tenant = Tenant::query()->find($grant->tenant_id);

        abort_unless($tenant instanceof Tenant, Response::HTTP_NOT_FOUND);

        // Tenant must be current so BelongsToTenant scopes work for dossier queries.
        $tenant->makeCurrent();
        // Fail fast if a later query runs without the portal tenant.
        $this->tenantContext->enableStrict();

        $window = $window->extend($grant);
        $request->session()->put(
            self::SESSION_EXPIRES_AT,
            $window->idleExpiresAt->getTimestamp(),
        );

        $request->attributes->set(self::REQUEST_ATTRIBUTE, $grant);
        $request->attributes->set(self::REQUEST_SESSION_WINDOW, $window);

        return $next($request);
    }

    /**
     * Tear the session down and send the client somewhere that explains itself,
     * instead of the app-wide 404 page that offers a guest a "Go to login" button.
     */
    private function endSession(Request $request, PortalSessionEndReason $reason): Response
    {
        $this->clearPortalSession($request);
        $request->session()->flash(self::SESSION_END_REASON, $reason->value);

        return to_route('portal.ended');
    }

    /**
     * Leaves SESSION_FIRM_NAME in place so the ended page stays firm-branded.
     * It is the tenant's public display name, already in the email that brought
     * the client here.
     */
    private function clearPortalSession(Request $request): void
    {
        $request->session()->forget([
            self::SESSION_GRANT_ID,
            self::SESSION_EXPIRES_AT,
            self::SESSION_ABSOLUTE_EXPIRES_AT,
        ]);
    }
}
