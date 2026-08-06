<?php

declare(strict_types=1);

use App\AudioProcessing\Jobs\ProcessAudioRecordingJob;
use App\DocumentProcessing\Jobs\ProcessUploadedDocumentJob;
use App\Dossiers\Jobs\GenerateDossierExportJob;
use Illuminate\Bus\UniqueLock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;

/**
 * @return array{0: object, 1: int}
 */
function uniqueJobFixture(string $jobClass): array
{
    $job = match ($jobClass) {
        ProcessUploadedDocumentJob::class => new ProcessUploadedDocumentJob(1, 'path.pdf'),
        ProcessAudioRecordingJob::class => new ProcessAudioRecordingJob(1, 'path.webm'),
        GenerateDossierExportJob::class => new GenerateDossierExportJob(1),
        default => throw new InvalidArgumentException($jobClass),
    };

    $tries = match ($jobClass) {
        ProcessUploadedDocumentJob::class => ProcessUploadedDocumentJob::MAX_TRIES,
        ProcessAudioRecordingJob::class => ProcessAudioRecordingJob::MAX_TRIES,
        GenerateDossierExportJob::class => GenerateDossierExportJob::MAX_TRIES,
        default => throw new InvalidArgumentException($jobClass),
    };

    $backoff = match ($jobClass) {
        ProcessUploadedDocumentJob::class => ProcessUploadedDocumentJob::BACKOFF_SECONDS,
        ProcessAudioRecordingJob::class => ProcessAudioRecordingJob::BACKOFF_SECONDS,
        GenerateDossierExportJob::class => GenerateDossierExportJob::BACKOFF_SECONDS,
        default => throw new InvalidArgumentException($jobClass),
    };

    $expected = ($job->timeout * $tries) + array_sum($backoff);

    return [$job, $expected];
}

test('unique jobs declare a uniqueFor window derived from timeout and retries', function (string $jobClass): void {
    [$job, $expected] = uniqueJobFixture($jobClass);

    expect($job->uniqueFor())->toBe($expected)
        ->and($job->uniqueFor())->toBeGreaterThan(0);
})->with([
    'document OCR' => ProcessUploadedDocumentJob::class,
    'audio transcription' => ProcessAudioRecordingJob::class,
    'dossier export' => GenerateDossierExportJob::class,
]);

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
