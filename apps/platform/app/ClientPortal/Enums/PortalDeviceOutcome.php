<?php

declare(strict_types=1);

namespace App\ClientPortal\Enums;

/**
 * The result of checking a redemption against the grant's known device.
 */
enum PortalDeviceOutcome: string
{
    /** No fingerprint was on record; this redemption became the baseline. */
    case FirstUse = 'first_use';

    /** Matches the fingerprint that redeemed the link originally. */
    case Recognised = 'recognised';

    /** Unfamiliar, and let through — staff have been told. */
    case NewDeviceAllowed = 'new_device_allowed';

    /** Unfamiliar, and refused because policy is `block`. */
    case NewDeviceBlocked = 'new_device_blocked';

    public function isBlocked(): bool
    {
        return $this === self::NewDeviceBlocked;
    }
}
