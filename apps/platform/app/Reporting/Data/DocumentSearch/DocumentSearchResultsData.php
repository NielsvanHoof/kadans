<?php

declare(strict_types=1);

namespace App\Reporting\Data\DocumentSearch;

use App\Dossiers\Data\UploadedDocuments\UploadedDocumentSearchHitData;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class DocumentSearchResultsData extends Data
{
    /**
     * @param  list<UploadedDocumentSearchHitData>  $hits
     */
    public function __construct(
        public readonly array $hits,
        public readonly bool $truncated,
    ) {}
}
