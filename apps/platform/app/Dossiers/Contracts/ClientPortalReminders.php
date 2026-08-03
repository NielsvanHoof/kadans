<?php

declare(strict_types=1);

namespace App\Dossiers\Contracts;

use App\Accounts\Models\User;
use App\Dossiers\Enums\DossierReminderSource;
use App\Dossiers\Models\Dossier;

/**
 * Issuing portal access is a ClientPortal concern, but reminder *scheduling*
 * (interval, next_reminder_at, outstanding-item guards) is a Dossiers concern.
 *
 * Dossiers depends on this contract; ClientPortal binds the implementation.
 *
 * Note the split with DossierArchived / DocumentRequestRejected: those announce
 * a fact and expect nothing back, so they are events. This one is a request for
 * a service whose return value the caller needs, so it is a contract.
 */
interface ClientPortalReminders
{
    /**
     * Issue a fresh portal grant for the dossier and notify its client.
     *
     * @return int The created grant id, for audit logging.
     */
    public function sendReminder(
        Dossier $dossier,
        User $grantCreator,
        DossierReminderSource $source,
    ): int;
}
