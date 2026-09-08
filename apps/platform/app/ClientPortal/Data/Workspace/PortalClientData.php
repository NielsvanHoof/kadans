<?php

declare(strict_types=1);

namespace App\ClientPortal\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Client identity shown on the portal page.
 *
 * Previously this nesting only existed inside PortalDossierData::toArray(),
 * which reshaped a flat clientName into `client: {name}`. Modelling it as a
 * real object keeps the payload the same and makes the shape visible.
 */
#[TypeScript]
final class PortalClientData extends Data
{
    public function __construct(
        public readonly string $name,
    ) {}
}
