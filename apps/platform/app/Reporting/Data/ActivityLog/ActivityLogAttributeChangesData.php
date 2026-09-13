<?php

declare(strict_types=1);

namespace App\Reporting\Data\ActivityLog;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Activity log attribute diff payload.
 */
#[TypeScript]
final class ActivityLogAttributeChangesData extends Data
{
    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $old
     */
    public function __construct(
        public readonly array $attributes,
        public readonly array $old,
    ) {}
}
