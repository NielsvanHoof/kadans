<?php

declare(strict_types=1);

use App\DocumentProcessing\Jobs\Ocr\ProcessUploadedDocumentJob;
use App\Platform\Support\Observability\WorkerJobs;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;

covers(...WorkerJobs::NAMES);

test('every queued job derives its lifecycle from timeout, retries and backoff', function (string $jobClass): void {
    // The formula is the whole point: a lock that outlives the job wedges its
    // subject after a SIGKILL, and one that expires early lets a second
    // dispatch in while the first is still working. Neither is visible in
    // staging, so the arithmetic is asserted rather than trusted.
    $expected = ($jobClass::timeoutSeconds() * $jobClass::MAX_TRIES)
        + array_sum($jobClass::BACKOFF_SECONDS);

    expect($jobClass::lifecycleSeconds())->toBe($expected)
        ->and($jobClass::lifecycleSeconds())->toBeGreaterThan(0);
})->with(WorkerJobs::NAMES);

test('every unique job reports its lifecycle as the lock window', function (string $jobClass): void {
    // uniqueFor() must not drift from lifecycleSeconds(): the claim on a
    // data-subject request is sized from the latter, and the two are asking
    // the same question about the same job.
    $reflection = new ReflectionClass($jobClass);

    expect($reflection->implementsInterface(ShouldBeUnique::class))->toBeTrue();

    $job = $reflection->newInstanceWithoutConstructor();

    expect($job->uniqueFor())->toBe($jobClass::lifecycleSeconds());
})->with(WorkerJobs::NAMES);

test('a dispatch is accepted once an abandoned unique lock expires', function (): void {
    Queue::fake();

    $job = new ProcessUploadedDocumentJob(42, 'tenants/1/file.pdf');

    // Simulate a lock left behind by a killed worker (short TTL — wall clock, not Carbon).
    expect(Cache::lock(UniqueLock::getKey($job), 1)->get())->toBeTrue();

    dispatch(new ProcessUploadedDocumentJob(42, 'tenants/1/file.pdf'));
    Queue::assertNothingPushed();

    Sleep::sleep(2);

    dispatch(new ProcessUploadedDocumentJob(42, 'tenants/1/file.pdf'));
    Queue::assertPushed(ProcessUploadedDocumentJob::class, 1);
});
