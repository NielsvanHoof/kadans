<?php

declare(strict_types=1);

namespace App\Clients\Events;

use App\Accounts\Models\User;
use App\Clients\Models\Client;

/**
 * A client was archived (soft-deleted).
 *
 * Raised inside the archiving transaction so synchronous listeners run
 * atomically with it — a listener that throws rolls the archive back. Mirrors
 * DossierArchived, and exists for the same reason: archiving has consequences
 * outside this domain that Clients (L4) cannot reach for itself.
 */
final readonly class ClientArchived
{
    public function __construct(
        public Client $client,
        public User $archivedBy,
    ) {}
}
