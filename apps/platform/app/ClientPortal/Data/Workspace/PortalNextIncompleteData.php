<?php

declare(strict_types=1);

namespace App\ClientPortal\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Next incomplete portal item hint.
 */
#[TypeScript]
final class PortalNextIncompleteData extends Data
{
    public function __construct(
        public readonly int $id,
        public readonly string $title,
    ) {}
}
