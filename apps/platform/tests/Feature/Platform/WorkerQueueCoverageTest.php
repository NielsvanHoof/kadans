<?php

declare(strict_types=1);

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

test('worker queues keep default first for queue work ordering', function (): void {
    expect(WorkerQueues::NAMES[0])->toBe('default')
        ->and(WorkerQueues::csv())->toBe('default,ocr,transcription,dossier-export');
});
