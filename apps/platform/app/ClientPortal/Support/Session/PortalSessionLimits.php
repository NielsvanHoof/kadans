<?php

declare(strict_types=1);

namespace App\ClientPortal\Support\Session;

use App\Tenancy\Queries\Settings\ResolveTenantSettingsQuery;

use function max;

/**
 * The two minute counts that bound one firm's portal sessions.
 *
 * Resolved per firm rather than read from config at the point of use, and
 * keyed by tenant id rather than the current tenant: the window is stamped at
 * link exchange, which runs outside the middleware that makes a tenant current,
 * so `Tenant::current()` is null there. Both callers hold the grant, which
 * carries the id.
 *
 * The values arrive already clamped — FirmSettingBounds decides how far a firm
 * may move each one, and the resolver applies that on read.
 */
final readonly class PortalSessionLimits
{
    private function __construct(
        public int $idleMinutes,
        public int $absoluteMinutes,
    ) {}

    public static function forTenant(int $tenantId): self
    {
        $settings = resolve(ResolveTenantSettingsQuery::class);

        return self::of(
            idleMinutes: $settings->portalSessionIdleMinutes($tenantId),
            absoluteMinutes: $settings->portalSessionAbsoluteMinutes($tenantId),
        );
    }

    /**
     * The ceiling can never be tighter than one idle window, or the first
     * request after redemption would already be too late.
     *
     * This is the one rule that is a relationship between the two settings
     * rather than a bound on either, which is why it lives here and not in
     * FirmSettingBounds — this is the only place that sees both numbers.
     */
    public static function of(int $idleMinutes, int $absoluteMinutes): self
    {
        return new self(
            idleMinutes: $idleMinutes,
            absoluteMinutes: max($idleMinutes, $absoluteMinutes),
        );
    }
}
