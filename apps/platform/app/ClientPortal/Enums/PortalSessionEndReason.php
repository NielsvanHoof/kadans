<?php

declare(strict_types=1);

namespace App\ClientPortal\Enums;

/**
 * Why a restricted portal session stopped working.
 *
 * Only these two states are ever disclosed to the client. Every other portal
 * denial stays a 404 (see the ClientPortal rules): disclosure is safe here and
 * only here because `portal.grant_id` is written solely by
 * ClientPortalSessionController after a successful token exchange and sits
 * behind a signed cookie, so reaching either case proves the client already
 * held a legitimate session.
 */
enum PortalSessionEndReason: string
{
    /** An idle or absolute deadline passed. The emailed link still works. */
    case TimedOut = 'timed_out';

    /** The grant itself was revoked or ran out. Re-opening the link will not help. */
    case AccessEnded = 'access_ended';
}
