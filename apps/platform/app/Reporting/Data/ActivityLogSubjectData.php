<?php

declare(strict_types=1);

namespace App\Reporting\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Activity log subject chip.
 */
#[TypeScript]
final class ActivityLogSubjectData extends Data
{
    public function __construct(
        public readonly string $type,
        public readonly int $id,
        public readonly ?string $name,
    ) {}
}
