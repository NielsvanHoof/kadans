<?php

declare(strict_types=1);

namespace App\Dossiers\Data\Lifecycle;

use App\Dossiers\Enums\DossierStatus;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Active dossier index table row.
 */
#[TypeScript]
final class DossierIndexRowData extends Data
{
    public function __construct(
        public readonly int $id,
        public readonly string $clientName,
        public readonly string $title,
        public readonly ?string $reference,
        public readonly ?int $periodYear,
        public readonly ?string $periodLabel,
        public readonly DossierStatus $status,
        public readonly ?string $dueDate,
        public readonly ?string $responsibleName,
    ) {}
}
