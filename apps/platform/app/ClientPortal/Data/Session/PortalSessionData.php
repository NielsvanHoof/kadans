<?php

declare(strict_types=1);

namespace App\ClientPortal\Data;

use App\ClientPortal\Support\PortalSessionWindow;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The client's own session deadlines, so the page can warn before it ends
 * rather than letting the next click fail.
 */
#[TypeScript]
final class PortalSessionData extends Data
{
    public function __construct(
        public readonly string $idleExpiresAt,
        public readonly string $absoluteExpiresAt,
    ) {}

    public static function fromWindow(PortalSessionWindow $window): self
    {
        return new self(
            idleExpiresAt: $window->idleExpiresAt->toIso8601String(),
            absoluteExpiresAt: $window->absoluteExpiresAt->toIso8601String(),
        );
    }
}
