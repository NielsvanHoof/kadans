<?php

declare(strict_types=1);

namespace App\Platform\Listeners;

use App\Platform\Support\PeakMemory;
use App\Platform\Support\WorkerQueues;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\Log;

use function in_array;

/**
 * Record what one heavy job actually cost, so worker sizing is measured.
 *
 * TASK-45 sized the Horizon supervisors from `config/horizon.php`'s own numbers
 * — 5 pools x a 256 MB recycle threshold — because nothing recorded what a job
 * really used. This is the measurement that replaces that arithmetic: compare
 * `peak_memory_mb` here against the supervisor's `memory` and the threshold
 * stops being a guess.
 *
 * A listener rather than an edit to each job: the export jobs delegate straight
 * to an action and have no completion log of their own, so per-job edits would
 * have produced six different shapes and missed every job added later.
 *
 * Scoped to {@see WorkerQueues::HEAVY}. The interactive queues are the ones that
 * arrive in floods — one bulk upload emits several hundred broadcast events —
 * and a log line each would bury the handful of readings worth having.
 *
 * Only successful jobs are seen. A job killed mid-run by PHP's `memory_limit`
 * never reaches JobProcessed, so an absent reading for a job whose worker
 * restarted is itself the signal.
 */
final readonly class LogHeavyJobMemoryUsage
{
    public function handle(JobProcessed $event): void
    {
        $queue = $event->job->getQueue();

        if (! in_array($queue, WorkerQueues::HEAVY, true)) {
            return;
        }

        Log::info('Workers: heavy job completed.', [
            'job' => $event->job->resolveName(),
            'queue' => $queue,
            'connection' => $event->connectionName,
            'attempts' => $event->job->attempts(),
            'peak_memory_mb' => PeakMemory::megabytes(),
        ]);
    }
}
