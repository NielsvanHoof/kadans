<?php

declare(strict_types=1);

namespace App\Dossiers\Data\Builder;

use App\Dossiers\Data\DocumentRequests\StaffDocumentRequestData;
use App\Dossiers\Enums\DossierStatus;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Lean dossier payload for the questionnaire builder page.
 */
#[TypeScript]
final class DossierBuilderData extends Data
{
    /**
     * @param  list<StaffDocumentRequestData>  $documentRequests
     */
    public function __construct(
        public readonly int $id,
        public readonly string $title,
        public readonly DossierStatus $status,
        public readonly array $documentRequests,
    ) {}
}
