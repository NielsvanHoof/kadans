<?php

declare(strict_types=1);

namespace App\ClientPortal\Enums;

/**
 * What to do when a magic link is redeemed from an unfamiliar device.
 */
enum NewPortalDeviceAction: string
{
    /** Let the client in and alert the responsible staff member. */
    case Notify = 'notify';

    /** Refuse the redemption, concealed as a 404 like every portal denial. */
    case Block = 'block';

    /** Record nothing. */
    case Ignore = 'ignore';
}
