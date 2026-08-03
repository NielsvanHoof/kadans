<?php

declare(strict_types=1);

namespace App\Shared\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Reusable {id, name, email} option for selects (staff, clients, …).
 */
#[TypeScript]
final class PersonOptionData extends Data
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $email,
    ) {}
}
