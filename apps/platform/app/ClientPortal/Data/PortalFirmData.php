<?php

declare(strict_types=1);

namespace App\ClientPortal\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Firm branding snippet on the client portal.
 */
#[TypeScript]
final class PortalFirmData extends Data
{
    public function __construct(public string $name) {}
}
