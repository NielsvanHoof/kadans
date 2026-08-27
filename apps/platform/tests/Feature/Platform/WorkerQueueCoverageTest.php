<?php

declare(strict_types=1);

use App\Platform\Jobs\GenerateDataSubjectExportJob;
use App\Platform\Support\WorkerQueues;

covers(WorkerQueues::class);

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
    expect(WorkerQueues::NAMES[0])->toBe('default')
        ->and(WorkerQueues::csv())->toBe('default,broadcasts,ocr,transcription,dossier-export');
});

test('horizon supervisors drain every worker queue', function (): void {
    $defaults = config('horizon.defaults.supervisor-1');

    expect($defaults['queue'])->toBe(WorkerQueues::NAMES)
        ->and($defaults['connection'])->toBe('redis')
        ->and($defaults['force'])->toBeTrue()
        ->and($defaults['tries'])->toBe(3)
        ->and($defaults['backoff'])->toBe(30)
        ->and($defaults['sleep'])->toBe(3)
        ->and($defaults['rest'])->toBe(0);

    foreach (['production', 'staging', 'local'] as $environment) {
        expect(config("horizon.environments.{$environment}.supervisor-1"))->not->toBeNull();
    }
});

test('horizon timeout sits between the longest job and redis retry_after', function (): void {
    $horizonTimeout = (int) config('horizon.defaults.supervisor-1.timeout');
    $retryAfter = (int) config('queue.connections.redis.retry_after');

    expect($horizonTimeout)->toBeGreaterThan(GenerateDataSubjectExportJob::TIMEOUT_SECONDS)
        ->and($horizonTimeout)->toBeLessThan($retryAfter);
});
