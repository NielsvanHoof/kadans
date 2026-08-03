<?php

declare(strict_types=1);

use App\AudioProcessing\Jobs\ProcessAudioRecordingJob;

test('transcription timeout hierarchy keeps http under job under redis retry_after', function (): void {
    // Pin values so a stale local .env cannot make this assertion flaky.
    config([
        'transcription.timeouts.http' => 270,
        'transcription.timeouts.job' => 900,
        'queue.connections.redis.retry_after' => 960,
    ]);

    $http = (int) config('transcription.timeouts.http');
    $job = (int) config('transcription.timeouts.job');
    $retryAfter = (int) config('queue.connections.redis.retry_after');

    expect($http)->toBeGreaterThan(0)
        ->and($job)->toBeGreaterThan($http)
        ->and($retryAfter)->toBeGreaterThan($job);
});

test('process audio recording job reads timeout from transcription config', function (): void {
    config(['transcription.timeouts.job' => 777]);

    $job = new ProcessAudioRecordingJob(audioRecordingId: 1);

    expect($job->timeout)->toBe(777);
});

test('chunking config defaults are sensible for long recordings', function (): void {
    expect((int) config('transcription.chunking.min_duration_seconds'))->toBeGreaterThan(0)
        ->and((int) config('transcription.chunking.max_chunk_seconds'))->toBeGreaterThan(0)
        ->and((int) config('transcription.chunking.max_chunk_seconds'))
        ->toBeLessThanOrEqual((int) config('transcription.chunking.min_duration_seconds'));
});
