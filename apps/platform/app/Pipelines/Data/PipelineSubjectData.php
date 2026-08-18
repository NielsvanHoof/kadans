<?php

declare(strict_types=1);

namespace App\Pipelines\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class PipelineSubjectData extends Data
{
    public function __construct(
        public readonly string $type,
        public readonly int $id,
        public readonly ?string $label,
        public readonly ?string $context,
        public readonly ?string $href,
    ) {}
}
