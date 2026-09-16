<?php

declare(strict_types=1);

namespace App\ClientPortal\Data\Workspace;

use App\Dossiers\Enums\DossierStatus;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Dossier section of the client portal page.
 */
#[TypeScript]
final class PortalDossierData extends Data
{
    /**
     * @param  list<PortalDocumentRequestData>  $documentRequests
     */
    public function __construct(
        public readonly int $id,
        public readonly string $title,
        public readonly ?string $reference,
        public readonly DossierStatus $status,
        public readonly ?string $dueDate,
        public readonly PortalClientData $client,
        public readonly string $expiresAt,
        public readonly PortalProgressData $progress,
        public readonly array $documentRequests,
    ) {}
}
