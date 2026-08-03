<?php

declare(strict_types=1);

namespace App\Dossiers\Data;

use App\Dossiers\Enums\DossierStatus;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Archived dossier index table row.
 */
#[TypeScript]
final class ArchivedDossierRowData extends Data
{
    public function __construct(
        public readonly int $id,
        public readonly string $title,
        public readonly ?string $reference,
        public readonly DossierStatus $status,
        public readonly string $clientName,
        public readonly bool $clientArchived,
        public readonly string $archivedAt,
    ) {}
}
