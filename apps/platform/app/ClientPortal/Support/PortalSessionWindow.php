<?php

declare(strict_types=1);

namespace App\ClientPortal\Support;

use App\ClientPortal\Models\ClientAccessGrant;
use Carbon\CarbonImmutable;

/**
 * The two deadlines that bound one restricted portal session.
 *
 * A single absolute deadline is what made the portal hostile: a client working
 * through a ten-item questionnaire on a phone routinely crossed it and their
 * next upload 404'd. So the idle deadline moves forward on every portal request
 * while the absolute one, stamped once at redemption, never does — an active
 * client is never ejected, and an abandoned or stolen session still dies.
 *
 * Both deadlines are capped by the grant's own `expires_at`: a session may never
 * outlive the link that opened it.
 *
 * How long each window runs is the firm's choice, so the minute counts arrive
 * as PortalSessionLimits rather than being read here — that keeps this a value
 * object that can be reasoned about without a container or a current tenant.
 */
final readonly class PortalSessionWindow
{
    public function __construct(
        public CarbonImmutable $idleExpiresAt,
        public CarbonImmutable $absoluteExpiresAt,
    ) {}

    /**
     * Open a fresh window at redemption.
     */
    public static function open(ClientAccessGrant $grant, PortalSessionLimits $limits): self
    {
        $absoluteExpiresAt = self::cappedByGrant(
            now()->addMinutes($limits->absoluteMinutes)->toImmutable(),
            $grant,
        );

        return new self(
            idleExpiresAt: self::earliest(
                self::cappedByGrant(now()->addMinutes($limits->idleMinutes)->toImmutable(), $grant),
                $absoluteExpiresAt,
            ),
            absoluteExpiresAt: $absoluteExpiresAt,
        );
    }

    /**
     * Rebuild the window from the two timestamps held in the portal session.
     */
    public static function fromTimestamps(int $idleExpiresAt, int $absoluteExpiresAt): self
    {
        return new self(
            idleExpiresAt: CarbonImmutable::createFromTimestamp($idleExpiresAt),
            absoluteExpiresAt: CarbonImmutable::createFromTimestamp($absoluteExpiresAt),
        );
    }

    /**
     * Push the idle deadline out, never past the absolute ceiling or the grant's
     * expiry. Called on every portal request that resolves a live grant.
     */
    public function extend(ClientAccessGrant $grant, PortalSessionLimits $limits): self
    {
        return new self(
            idleExpiresAt: self::earliest(
                self::cappedByGrant(now()->addMinutes($limits->idleMinutes)->toImmutable(), $grant),
                $this->absoluteExpiresAt,
            ),
            absoluteExpiresAt: $this->absoluteExpiresAt,
        );
    }

    /**
     * Whether either deadline has passed. The ceiling is checked too — an
     * extended idle deadline means nothing once the ceiling is behind us.
     */
    public function hasEnded(): bool
    {
        return ! now()->lessThan($this->idleExpiresAt)
            || ! now()->lessThan($this->absoluteExpiresAt);
    }

    private static function cappedByGrant(CarbonImmutable $deadline, ClientAccessGrant $grant): CarbonImmutable
    {
        return self::earliest($deadline, $grant->expires_at->toImmutable());
    }

    private static function earliest(CarbonImmutable $first, CarbonImmutable $second): CarbonImmutable
    {
        return $first->lessThan($second) ? $first : $second;
    }
}
