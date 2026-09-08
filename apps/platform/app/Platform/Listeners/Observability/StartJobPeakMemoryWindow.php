<?php

declare(strict_types=1);

namespace App\Platform\Listeners;

use App\Shared\Support\PeakMemory;
use Illuminate\Queue\Events\JobProcessing;

/**
 * Reset the process peak before each job so {@see LogHeavyJobMemoryUsage} measures one job.
 *
 * Paired with that listener rather than folded into it: the window has to open
 * before `handle()` runs and close after, and a single listener cannot see both
 * edges. Runs for every job, not just the heavy ones — an unreset peak from a
 * broadcast job would leak into the next heavy job's reading.
 */
final readonly class StartJobPeakMemoryWindow
{
    public function handle(JobProcessing $event): void
    {
        PeakMemory::startWindow();
    }
}
