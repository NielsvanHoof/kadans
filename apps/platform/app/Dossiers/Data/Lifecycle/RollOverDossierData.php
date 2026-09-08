<?php

declare(strict_types=1);

namespace App\Dossiers\Data;

/**
 * Staff-supplied details for the dossier a roll-over creates.
 *
 * Plain value object rather than a Spatie Data payload: this only ever travels
 * from RollOverDossierRequest into RollOverDossierAction, and no TypeScript
 * consumer reads it (see ReminderDraftContextData for the same call).
 *
 * `reference` is never inherited from the source dossier — dossiers unique on
 * (tenant_id, reference), so the new period always needs its own.
 */
final readonly class RollOverDossierData
{
    public function __construct(
        public string $title,
        public ?string $reference,
        public ?int $periodYear,
        public ?string $periodLabel,
        public ?string $dueDate,
        public bool $carryAnswers,
    ) {}
}
