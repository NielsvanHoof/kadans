<?php

declare(strict_types=1);

namespace App\Reporting\Data;

use App\Dossiers\Enums\DocumentRequestStatus;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Media library index row.
 */
#[TypeScript]
final class MediaLibraryDocumentRowData extends Data
{
    /**
     * @param  list<MediaLibraryUploadedDocumentData>  $uploadedDocuments
     */
    public function __construct(
        public readonly int $id,
        public readonly string $title,
        public readonly DocumentRequestStatus $status,
        public readonly ?string $updatedAt,
        public readonly MediaLibraryDossierSummaryData $dossier,
        public readonly string $clientName,
        public readonly array $uploadedDocuments,
    ) {}
}
