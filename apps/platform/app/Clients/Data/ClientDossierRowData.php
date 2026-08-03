<?php

declare(strict_types=1);

namespace App\Clients\Data;

use App\Dossiers\Enums\DossierStatus;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Dossier row nested under the client show page.
 */
#[TypeScript]
final class ClientDossierRowData extends Data
{
    public function __construct(
        public readonly int $id,
        public readonly string $title,
        public readonly ?string $reference,
        public readonly DossierStatus $status,
        public readonly string $clientName,
        public readonly string $updatedAt,
    ) {}
}
