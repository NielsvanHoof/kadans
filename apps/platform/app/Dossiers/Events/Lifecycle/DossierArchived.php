<?php

declare(strict_types=1);

namespace App\Dossiers\Events\Lifecycle;

use App\Accounts\Models\User;
use App\Dossiers\Models\Dossier;

/**
 * A dossier was archived (soft-deleted).
 *
 * Raised inside the archiving transaction so synchronous listeners run
 * atomically with it — a listener that throws rolls the archive back.
 */
final readonly class DossierArchived
{
    public function __construct(
        public Dossier $dossier,
        public User $archivedBy,
    ) {}
}
