<?php

declare(strict_types=1);

namespace App\Clients\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Client header summary on the client show page.
 */
#[TypeScript]
final class ClientSummaryData extends Data
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $email,
        public readonly int $activeDossiersCount,
        public readonly int $archivedDossiersCount,
    ) {}
}
