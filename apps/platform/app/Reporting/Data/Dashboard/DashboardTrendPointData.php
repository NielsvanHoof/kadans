<?php

declare(strict_types=1);

namespace App\Reporting\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One weekly bucket of client throughput.
 *
 * Single series on purpose. Dossier completion has no timestamp column (only a
 * `dossier.completed` audit event), and pairing dossiers with documents would
 * put two different units on one axis. One measure, one colour — which also
 * keeps the chart clear of the `--chart-*` palette's limits: only chart-1 and
 * chart-4 separate safely under colour-vision deficiency, and even that pair is
 * marginal.
 */
#[TypeScript]
final class DashboardTrendPointData extends Data
{
    public function __construct(
        /** Monday of the bucket, Y-m-d. */
        public readonly string $weekStart,
        /** Document requests answered by clients during the week. */
        public readonly int $documentsReceived,
    ) {}
}
