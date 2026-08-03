<?php

declare(strict_types=1);

namespace App\Dossiers\Support;

/**
 * Exclusive builder edit-lease timing.
 *
 * The holder must heartbeat before TTL elapses; otherwise the lock expires
 * and another adviser can take the form.
 */
final class DossierBuilderLock
{
    /** Seconds without a heartbeat before the lock is considered free. */
    public const int TTL_SECONDS = 300;

    /** How often the holding client should POST a heartbeat. */
    public const int HEARTBEAT_INTERVAL_SECONDS = 60;

    /** How often a read-only visitor should retry acquire after expiry. */
    public const int POLL_INTERVAL_SECONDS = 30;
}
