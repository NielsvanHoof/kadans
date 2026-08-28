<?php

declare(strict_types=1);

use App\DocumentProcessing\Jobs\ApplyTextractResultJob;
use App\Platform\Listeners\LogHeavyJobMemoryUsage;
use App\Platform\Listeners\StartJobPeakMemoryWindow;
use App\Platform\Support\WorkerQueues;
use App\Shared\Support\PeakMemory;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Log\LogManager;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Log;

covers(LogHeavyJobMemoryUsage::class);

function jobProcessedOn(string $queue, string $name = ApplyTextractResultJob::class): JobProcessed
{
    $job = Mockery::mock(Job::class);
    $job->shouldReceive('getQueue')->andReturn($queue);
    $job->shouldReceive('resolveName')->andReturn($name);
    $job->shouldReceive('attempts')->andReturn(1);

    return new JobProcessed('redis', $job);
}

test('a heavy job records its peak memory', function (): void {
    $captured = [];

    Log::shouldReceive('info')
        ->once()
        ->with('Workers: heavy job completed.', Mockery::capture($captured));

    resolve(LogHeavyJobMemoryUsage::class)->handle(jobProcessedOn('ocr'));

    expect($captured['job'])->toBe(ApplyTextractResultJob::class)
        ->and($captured['queue'])->toBe('ocr')
        ->and($captured['connection'])->toBe('redis')
        ->and($captured['attempts'])->toBe(1)
        ->and($captured['peak_memory_mb'])->toBeFloat()
        ->and($captured['peak_memory_mb'])->toBeGreaterThan(0.0);
});

test('every heavy queue is covered and the interactive floods are not', function (): void {
    // Broadcast events arrive several hundred at a time on one bulk upload; a
    // line each would bury the handful of readings worth having.
    Log::spy();

    $listener = resolve(LogHeavyJobMemoryUsage::class);

    foreach (WorkerQueues::NAMES as $queue) {
        $listener->handle(jobProcessedOn($queue));
    }

    Log::shouldHaveReceived('info')
        ->with('Workers: heavy job completed.', Mockery::any())
        ->times(count(WorkerQueues::HEAVY));

    expect(WorkerQueues::HEAVY)->not->toBeEmpty()
        ->and(WorkerQueues::INTERACTIVE)->not->toBeEmpty();
});

test('the peak window resets between jobs so one job cannot inherit another reading', function (): void {
    // The process peak never decreases on its own, so without the reset every
    // job on a long-lived worker reports the high-water mark of the jobs before
    // it. Allocate, release, reset, and the reading must fall back.
    $ballast = str_repeat('x', 24 * 1024 * 1024);
    $inflated = PeakMemory::megabytes();
    unset($ballast);

    expect($inflated)->toBeGreaterThan(24.0);

    resolve(StartJobPeakMemoryWindow::class)->handle(
        new JobProcessing('redis', Mockery::mock(Job::class)),
    );

    expect(PeakMemory::megabytes())->toBeLessThan($inflated);
});

test('both queue listeners are discovered', function (): void {
    // Discovery reads the handle() type hint; losing it would silence the
    // measurement without failing anything.
    expect(Log::getFacadeRoot())->toBeInstanceOf(LogManager::class);

    $this->artisan('event:list')
        ->expectsOutputToContain(StartJobPeakMemoryWindow::class)
        ->expectsOutputToContain(LogHeavyJobMemoryUsage::class)
        ->assertSuccessful();
});
