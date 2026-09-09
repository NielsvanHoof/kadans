<?php

declare(strict_types=1);

namespace App\Reporting\Data\ActivityLog;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Compact activity log request metadata.
 */
#[TypeScript]
final class ActivityLogPropertiesData extends Data
{
    public function __construct(
        public readonly ?string $ip,
        public readonly ?string $route,
    ) {}
}
