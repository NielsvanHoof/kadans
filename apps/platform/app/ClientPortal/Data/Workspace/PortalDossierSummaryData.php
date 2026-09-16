<?php

declare(strict_types=1);

namespace App\ClientPortal\Data\Workspace;

use App\Dossiers\Enums\DossierStatus;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One row on the client's list of everything their firm is waiting for.
 *
 * Carries counts rather than the questionnaire itself: the list exists to let
 * someone decide what to open next, and loading every document request of every
 * dossier to render it would be the expensive way to answer a small question.
 */
#[TypeScript]
final class PortalDossierSummaryData extends Data
{
    public function __construct(
        public readonly int $id,
        public readonly string $title,
        public readonly ?string $reference,
        public readonly DossierStatus $status,
        public readonly ?string $dueDate,
        public readonly int $remaining,
        public readonly int $total,
    ) {}
}
