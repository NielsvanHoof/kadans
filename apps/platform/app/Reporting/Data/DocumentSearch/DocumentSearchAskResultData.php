<?php

declare(strict_types=1);

namespace App\Reporting\Data\DocumentSearch;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class DocumentSearchAskResultData extends Data
{
    /**
     * @param  list<DocumentSearchCitationData>  $citations
     */
    public function __construct(
        public readonly string $answer,
        public readonly array $citations,
    ) {}
}
