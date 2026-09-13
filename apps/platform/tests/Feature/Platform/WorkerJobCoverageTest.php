<?php

declare(strict_types=1);

use App\Platform\Support\Observability\WorkerJobs;
use App\Shared\Contracts\Queue\TimeBoundedJob;
use Illuminate\Contracts\Queue\ShouldQueue;

covers(WorkerJobs::class);

/**
 * The sibling of WorkerQueueCoverageTest: that one guards the queue drain list
 * against the onQueue() literals on disk, this one guards the job registry
 * against the job classes on disk.
 *
 * @return list<class-string>
 */
function queuedJobClassesOnDisk(): array
{
    $classes = [];

    foreach (glob(app_path('*/Jobs/*.php')) ?: [] as $path) {
        $source = (string) file_get_contents($path);

        if (preg_match('/namespace ([^;]+);/', $source, $namespace) !== 1) {
            continue;
        }

        $classes[] = $namespace[1].'\\'.basename($path, '.php');
    }

    // Ocr and other concern folders nest one level deeper.
    foreach (glob(app_path('*/Jobs/*/*.php')) ?: [] as $path) {
        $source = (string) file_get_contents($path);

        if (preg_match('/namespace ([^;]+);/', $source, $namespace) !== 1) {
            continue;
        }

        $classes[] = $namespace[1].'\\'.basename($path, '.php');
    }

    return $classes;
}

test('every queued job on disk is registered', function (): void {
    // An unregistered job weakens two guards at once and fails neither:
    // ProductionConfigurationValidator would let retry_after sit below its
    // timeout, and UniqueJobLockExpiryTest would never check its unique lock.
    $onDisk = queuedJobClassesOnDisk();

    expect($onDisk)->not->toBeEmpty();

    foreach ($onDisk as $job) {
        expect(WorkerJobs::NAMES)->toContain($job);
    }
});

test('the registry lists nothing that is not a queued job', function (): void {
    expect(WorkerJobs::NAMES)->toBe(array_values(array_unique(WorkerJobs::NAMES)));

    foreach (WorkerJobs::NAMES as $job) {
        expect(class_exists($job))->toBeTrue("[{$job}] is registered but does not exist.")
            ->and(is_a($job, ShouldQueue::class, true))->toBeTrue("[{$job}] is not a queued job.")
            ->and(is_a($job, TimeBoundedJob::class, true))->toBeTrue("[{$job}] does not declare its timing envelope.");
    }
});

test('the longest timeout stays below the connection retry_after', function (): void {
    // The ordering ProductionConfigurationValidator refuses to boot without.
    // Asserted here too so the suite catches it before a deploy does.
    $retryAfter = config('queue.connections.redis.retry_after');

    expect(WorkerJobs::longestTimeoutSeconds())->toBeLessThan((int) $retryAfter);
});
