<?php

declare(strict_types=1);

namespace App\Reporting\Data;

use App\Dossiers\Enums\DossierStatus;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A dated dossier on the current user's short-term agenda.
 */
#[TypeScript]
final class DashboardAgendaItemData extends Data
{
    public function __construct(
        public readonly int $id,
        public readonly string $title,
        public readonly string $clientName,
        public readonly DossierStatus $status,
        public readonly string $dueDate,
        /** Whole days until due; negative once the date has passed. */
        public readonly int $daysRemaining,
    ) {}
}
