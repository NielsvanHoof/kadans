<?php

declare(strict_types=1);

namespace App\ClientPortal\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Progress summary for the client portal questionnaire.
 */
#[TypeScript]
final class PortalProgressData extends Data
{
    public function __construct(
        public readonly int $total,
        public readonly int $completed,
        public readonly int $approved,
        public readonly int $remaining,
        public readonly ?PortalNextIncompleteData $nextIncomplete,
    ) {}
}
