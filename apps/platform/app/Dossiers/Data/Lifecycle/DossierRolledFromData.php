<?php

declare(strict_types=1);

namespace App\Dossiers\Data\Lifecycle;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The previous period a dossier was rolled over from.
 *
 * Enough to render a link back and name the period, without loading the whole
 * source dossier into the show payload.
 */
#[TypeScript]
final class DossierRolledFromData extends Data
{
    public function __construct(
        public readonly int $id,
        public readonly string $title,
        public readonly ?string $periodLabel,
        public readonly ?int $periodYear,
    ) {}
}
