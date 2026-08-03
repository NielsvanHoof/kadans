<?php

declare(strict_types=1);

namespace App\Clients\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Archived client index row.
 */
#[TypeScript]
final class ArchivedClientRowData extends Data
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $email,
        public readonly int $dossiersCount,
        public readonly string $archivedAt,
    ) {}
}
