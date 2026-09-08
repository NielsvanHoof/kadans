<?php

declare(strict_types=1);

namespace App\Dossiers\Data\Lifecycle;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Document-request status counts on dossier show.
 */
#[TypeScript]
final class DossierReviewSummaryData extends Data
{
    public function __construct(
        public readonly int $total,
        public readonly int $pending,
        public readonly int $submitted,
        public readonly int $accepted,
        public readonly int $rejected,
    ) {}
}
