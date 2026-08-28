<?php

declare(strict_types=1);

use App\Platform\Jobs\GenerateDataSubjectExportJob;
use App\Platform\Support\WorkerQueues;
use Illuminate\Support\Facades\Config;

covers(WorkerQueues::class);

/**
 * @return array<string, array<string, mixed>>
 */
function horizonSupervisors(): array
{
    /** @var array<string, array<string, mixed>> $supervisors */
    $supervisors = Config::array('horizon.defaults');

    return $supervisors;
}

test('worker queues cover every dedicated onQueue literal', function (): void {
    $dedicatedQueues = collect(glob(app_path('*/Jobs/*.php')) ?: [])
        ->flatMap(function (string $job): array {
            preg_match_all("/->onQueue\('([^']+)'\)/", (string) file_get_contents($job), $matches);

            return $matches[1];
        })
        ->unique()
        ->values();

    expect($dedicatedQueues)->not->toBeEmpty();

    foreach ($dedicatedQueues as $queue) {
        expect(WorkerQueues::NAMES)->toContain($queue);
    }
});

test('worker queues cover every broadcastQueue literal', function (): void {
    // Broadcast events name their queue with a $broadcastQueue property rather
    // than onQueue(), so the job scan above cannot see them. A broadcast queue
    // missing from NAMES is worse than a job one: nothing fails, the events
    // just pile up unconsumed and the live UI quietly stops updating.
    $broadcastQueues = collect(glob(app_path('*/Events/*.php')) ?: [])
        ->flatMap(function (string $event): array {
            preg_match_all('/\$broadcastQueue = \'([^\']+)\'/', (string) file_get_contents($event), $matches);

            return $matches[1];
        })
        ->unique()
        ->values();

    expect($broadcastQueues)->not->toBeEmpty();

    foreach ($broadcastQueues as $queue) {
        expect(WorkerQueues::NAMES)->toContain($queue);
    }
});

test('worker queues keep default first for queue work ordering', function (): void {
    // Pinned as a list rather than through a csv() helper: the comma-separated
    // form only ever existed for a Managed Queues field this project does not
    // use. Order still matters — it is the pool Horizon favours when idle.
    expect(WorkerQueues::NAMES[0])->toBe('default')
        ->and(WorkerQueues::NAMES)->toBe([
            'default',
            'broadcasts',
            'ocr',
            'transcription',
            'dossier-export',
        ]);
});

test('the interactive and heavy groups partition every worker queue', function (): void {
    // A queue in neither group is drained by nobody, and a queue in both is
    // drained by two supervisors with different memory and timeout budgets.
    // Asserting the partition rather than each list keeps adding a queue a
    // one-line change that still cannot go unassigned.
    $grouped = [...WorkerQueues::INTERACTIVE, ...WorkerQueues::HEAVY];

    expect($grouped)->toEqualCanonicalizing(WorkerQueues::NAMES)
        ->and(array_unique($grouped))->toHaveSameSize(WorkerQueues::NAMES);
});

test('horizon supervisors drain every worker queue exactly once', function (): void {
    $supervisors = horizonSupervisors();

    expect($supervisors)->toHaveKeys(['supervisor-interactive', 'supervisor-heavy']);

    $drained = collect($supervisors)->flatMap(fn (array $supervisor): array => $supervisor['queue'])->all();

    expect($drained)->toEqualCanonicalizing(WorkerQueues::NAMES)
        ->and(array_unique($drained))->toHaveSameSize(WorkerQueues::NAMES);

    foreach ($supervisors as $name => $supervisor) {
        expect($supervisor['connection'])->toBe('redis', $name)
            ->and($supervisor['force'])->toBeTrue($name)
            ->and($supervisor['tries'])->toBe(3, $name)
            ->and($supervisor['backoff'])->toBe(30, $name)
            ->and($supervisor['sleep'])->toBe(3, $name)
            ->and($supervisor['rest'])->toBe(0, $name);
    }

    foreach (['production', 'staging', 'local'] as $environment) {
        expect(Config::array("horizon.environments.{$environment}"))
            ->toHaveKeys(['supervisor-interactive', 'supervisor-heavy']);
    }
});

/**
 * The regression this split exists for.
 *
 * `balance => 'auto'` builds one pool per queue and `minProcesses` is a per-pool
 * floor, so the resident worker count is at least the queue count. When
 * `maxProcesses` sits at or below that, AutoScaler::scalePool derives a
 * `maxUpShift` of `max(0, maxProcesses - totalProcessCount)` = 0 and the
 * supervisor can never add a worker — silently, with `balance => 'auto'` still
 * in the config looking correct.
 */
test('every supervisor can still scale up from its per-pool floor', function (): void {
    foreach (horizonSupervisors() as $name => $supervisor) {
        $floor = count($supervisor['queue']) * $supervisor['minProcesses'];

        expect($supervisor['maxProcesses'])->toBeGreaterThan($floor, $name);
    }
});

test('every supervisor recycles on a job count, not only on its memory limit', function (): void {
    // maxJobs and maxTime at 0 left the memory limit as the only recycle
    // trigger, which turns `memory` into a target every worker climbs to.
    foreach (horizonSupervisors() as $name => $supervisor) {
        expect($supervisor['maxJobs'])->toBeGreaterThan(0, $name);
    }
});

test('the supervisor draining dossier-export outlives the longest job but not retry_after', function (): void {
    $supervisor = collect(horizonSupervisors())
        ->first(fn (array $supervisor): bool => in_array('dossier-export', $supervisor['queue'], true));

    expect($supervisor)->not->toBeNull();

    $retryAfter = Config::integer('queue.connections.redis.retry_after');

    expect($supervisor['timeout'])->toBeGreaterThan(GenerateDataSubjectExportJob::TIMEOUT_SECONDS)
        ->and($supervisor['timeout'])->toBeLessThan($retryAfter);
});

test('the combined worker memory ceiling stays under the single-supervisor original', function (): void {
    // 5 queues x 256 MB was the fixed resident cost of the old topology; the
    // split has to beat it at full scale, not merely while idle.
    $ceiling = collect(horizonSupervisors())
        ->sum(fn (array $supervisor): int => $supervisor['maxProcesses'] * $supervisor['memory']);

    expect($ceiling)->toBeLessThan(count(WorkerQueues::NAMES) * 256);
});
