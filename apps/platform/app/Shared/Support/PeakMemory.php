<?php

declare(strict_types=1);

namespace App\Shared\Support;

use function memory_get_peak_usage;
use function memory_reset_peak_usage;
use function round;

/**
 * Peak memory for the current worker process, for job completion logs.
 *
 * Every heavy job reports this under the same `peak_memory_mb` key so worker
 * sizing is a query against the log stream rather than arithmetic over
 * `config/horizon.php`. TASK-45 sized the supervisors from the config's own
 * numbers; this is how the next round gets measured instead.
 *
 * Peak, not current: the provider payloads these jobs decode are freed before
 * the job returns, so `memory_get_usage()` at the end of a job reports the
 * plateau and hides the spike that actually drives the memory limit.
 *
 * Real allocated pages rather than emalloc bookkeeping, because Horizon's
 * `memory` threshold and the container's limit both count the former.
 *
 * Lives in Shared (L0), not with the Platform listeners that first used it.
 * The L4 extraction pipelines report the same key, and L4 may not reach
 * Platform (L6) — as the arch preset in tests/Pest.php caught. It holds no
 * domain knowledge, so L0 is where it belongs rather than something to
 * invert behind a contract.
 */
final class PeakMemory
{
    public static function megabytes(): float
    {
        return round(memory_get_peak_usage(true) / 1024 / 1024, 1);
    }

    /**
     * Start a fresh measurement window.
     *
     * The process peak never decreases, so without this every job on a
     * long-lived worker reports the high-water mark of every job before it —
     * which would read as "the export job used 190 MB" when the export job was
     * merely unlucky enough to run after an OCR job that did.
     *
     * Safe to call between jobs: Worker::memoryExceeded reads
     * `memory_get_usage()`, not the peak, so worker recycling is unaffected.
     */
    public static function startWindow(): void
    {
        memory_reset_peak_usage();
    }
}
