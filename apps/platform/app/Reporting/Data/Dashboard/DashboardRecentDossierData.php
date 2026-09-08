<?php

declare(strict_types=1);

namespace App\Reporting\Data;

use App\Dossiers\Enums\DossierStatus;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Recent dossier card on the workspace dashboard.
 */
#[TypeScript]
final class DashboardRecentDossierData extends Data
{
    public function __construct(
        public readonly int $id,
        public readonly string $title,
        public readonly ?string $reference,
        public readonly DossierStatus $status,
        public readonly string $clientName,
        public readonly ?string $dueDate,
        public readonly ?string $responsibleName,
        public readonly string $updatedAt,
    ) {}
}
