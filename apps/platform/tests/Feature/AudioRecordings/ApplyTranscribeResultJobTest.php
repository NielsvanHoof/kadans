<?php

declare(strict_types=1);

use App\AudioProcessing\Jobs\ApplyTranscribeResultJob;
use App\Clients\Models\Client;
use App\DocumentProcessing\Enums\TranscriptionStatus;
use App\Dossiers\Models\AudioRecording;
use App\Dossiers\Models\Dossier;

covers(ApplyTranscribeResultJob::class);

/**
 * Declared here rather than reused from the consumer test: Pest helpers are
 * per-file, and a shard that loads only this file would not see that one.
 */
function recordingAwaitingTranscribe(string $jobId): AudioRecording
{
    ['tenant' => $tenant] = provisionWorkspace();

    $client = Client::factory()->create(['tenant_id' => $tenant->id]);
    $dossier = Dossier::factory()->create(['tenant_id' => $tenant->id, 'client_id' => $client->id]);

    return AudioRecording::factory()->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $dossier->id,
        'transcription_status' => TranscriptionStatus::Processing,
        'transcribe_job_id' => $jobId,
    ]);
}

test('exhausted retries mark the recording failed rather than leaving it processing', function (): void {
    $recording = recordingAwaitingTranscribe('transcribe-job-exhausted');

    // Mirrors the OCR job: the terminal path used to be the consumer counting
    // SQS receives, and is now the job's own attempt budget.
    new ApplyTranscribeResultJob(
        ['TranscriptionJobName' => 'transcribe-job-exhausted', 'TranscriptionJobStatus' => 'COMPLETED'],
        'transcribe-job-exhausted',
    )->failed(new RuntimeException('Persistent transcript failure.'));

    $recording->refresh();

    expect($recording->transcription_status)->toBe(TranscriptionStatus::Failed)
        ->and($recording->transcription_error)->toBe('Persistent transcript failure.');
});

test('the unique lock releases after the worst-case attempt budget', function (): void {
    $job = new ApplyTranscribeResultJob(['TranscriptionJobName' => 'j'], 'transcribe-job-unique');

    expect($job->uniqueId())->toBe('transcribe-job-unique')
        ->and($job->uniqueFor())->toBe(
            (ApplyTranscribeResultJob::TIMEOUT_SECONDS * ApplyTranscribeResultJob::MAX_TRIES)
            + array_sum(ApplyTranscribeResultJob::BACKOFF_SECONDS),
        )
        ->and($job->uniqueFor())->toBeLessThan(config('queue.connections.redis.retry_after'));
});
