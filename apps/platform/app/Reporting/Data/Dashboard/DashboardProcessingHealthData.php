<?php

declare(strict_types=1);

namespace App\Reporting\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * OCR, transcription and export runs that need a human.
 *
 * Deferred rather than cached: Pipeline does not use InvalidatesTenantCaches,
 * so a cached count would keep reporting a failure for up to the full TTL after
 * someone had already retried it.
 */
#[TypeScript]
final class DashboardProcessingHealthData extends Data
{
    /**
     * @param  list<DashboardPipelineFailureData>  $recent
     */
    public function __construct(
        public readonly int $failed,
        public readonly int $stuck,
        /** Most recent runs needing attention, newest first. */
        public readonly array $recent,
    ) {}
}
