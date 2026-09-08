<?php

declare(strict_types=1);

namespace App\Dossiers\Data;

/**
 * Prompt context for DossierReminderDraftAgent — assembled server-side only.
 * Never built from client-posted data, since outstanding items must reflect
 * the dossier's real state, not whatever a caller claims it to be.
 */
final readonly class ReminderDraftContextData
{
    /**
     * @param  list<string>  $outstandingItemTitles
     */
    public function __construct(
        public string $dossierTitle,
        public string $clientName,
        public ?string $dueDate,
        public array $outstandingItemTitles,
    ) {}
}
