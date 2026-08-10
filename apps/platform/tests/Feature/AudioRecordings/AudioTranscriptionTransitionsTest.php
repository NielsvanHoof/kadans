<?php

declare(strict_types=1);

use App\AudioProcessing\Transitions\AudioTranscriptionTransitions;
use App\Clients\Models\Client;
use App\DocumentProcessing\Enums\TranscriptionStatus;
use App\Dossiers\Models\AudioRecording;
use App\Dossiers\Models\Dossier;
use App\Tenancy\Models\Tenant;

covers(AudioTranscriptionTransitions::class);

beforeEach(function (): void {
    $this->transitions = resolve(AudioTranscriptionTransitions::class);
});

test('claim moves a pending recording to processing for the expected path', function (): void {
    $recording = createRecordingForTranscriptionTransitions();

    $claimed = $this->transitions->claim($recording, $recording->path);

    expect($claimed)->toBeInstanceOf(AudioRecording::class)
        ->and($claimed->transcription_status)->toBe(TranscriptionStatus::Processing)
        ->and($claimed->transcribe_job_id)->toBeNull()
        ->and($claimed->transcription_started_at)->not->toBeNull();
});

test('claim refuses a completed recording', function (): void {
    $recording = createRecordingForTranscriptionTransitions([
        'transcription_status' => TranscriptionStatus::Completed,
        'transcript' => 'done',
        'transcription_finished_at' => now(),
    ]);

    expect($this->transitions->claim($recording, $recording->path))->toBeNull();
});

test('claim refuses an in-flight Transcribe job', function (): void {
    $recording = createRecordingForTranscriptionTransitions([
        'transcription_status' => TranscriptionStatus::Processing,
        'transcribe_job_id' => 'job-in-flight',
        'transcription_started_at' => now(),
    ]);

    expect($this->transitions->claim($recording, $recording->path))->toBeNull()
        ->and($recording->fresh()->transcribe_job_id)->toBe('job-in-flight');
});

test('claim refuses a stale path revision', function (): void {
    $recording = createRecordingForTranscriptionTransitions();
    $stalePath = $recording->path;

    $recording->forceFill(['path' => 'audio/replacement.mp3'])->save();

    expect($this->transitions->claim($recording, $stalePath))->toBeNull()
        ->and($recording->fresh()->transcription_status)->toBe(TranscriptionStatus::Pending);
});

test('complete persists transcript only while processing for the matching job', function (): void {
    $recording = createRecordingForTranscriptionTransitions([
        'transcription_status' => TranscriptionStatus::Processing,
        'transcribe_job_id' => 'job-1',
        'transcription_started_at' => now(),
    ]);

    $segments = json_encode([[
        'text' => 'hello',
        'speaker' => 'Speaker 1',
        'start_seconds' => 0,
        'end_seconds' => 1,
    ]], JSON_THROW_ON_ERROR);

    expect($this->transitions->complete($recording, 'job-1', 'hello', $segments))->toBeTrue()
        ->and($recording->fresh())
        ->transcription_status->toBe(TranscriptionStatus::Completed)
        ->transcript->toBe('hello')
        ->and($this->transitions->complete($recording->fresh(), 'job-1', 'ignored', '[]'))
        ->toBeFalse();
});

test('failWhileProcessing requires the matching job id', function (): void {
    $recording = createRecordingForTranscriptionTransitions([
        'transcription_status' => TranscriptionStatus::Processing,
        'transcribe_job_id' => 'job-1',
        'transcription_started_at' => now(),
    ]);

    expect($this->transitions->failWhileProcessing($recording, 'wrong-job', 'boom'))->toBeFalse()
        ->and($this->transitions->failWhileProcessing($recording, 'job-1', 'boom'))->toBeTrue()
        ->and($recording->fresh())
        ->transcription_status->toBe(TranscriptionStatus::Failed)
        ->transcription_error->toBe('boom');
});

test('failForPath marks permanent failure for the current path revision', function (): void {
    $recording = createRecordingForTranscriptionTransitions([
        'transcription_status' => TranscriptionStatus::Processing,
        'transcription_started_at' => now(),
    ]);

    expect($this->transitions->failForPath($recording, $recording->path, 'exhausted'))->toBeTrue()
        ->and($recording->fresh()->transcription_status)->toBe(TranscriptionStatus::Failed)
        ->and($this->transitions->failForPath($recording->fresh(), 'audio/other.mp3', 'stale'))->toBeFalse();
});

test('resetForRetry returns a processing recording to pending', function (): void {
    $recording = createRecordingForTranscriptionTransitions([
        'transcription_status' => TranscriptionStatus::Processing,
        'transcribe_job_id' => 'job-1',
        'transcription_started_at' => now(),
    ]);

    $this->transitions->resetForRetry($recording, $recording->path);

    expect($recording->fresh())
        ->transcription_status->toBe(TranscriptionStatus::Pending)
        ->transcribe_job_id->toBeNull();
});

/**
 * @param  array<string, mixed>  $overrides
 */
function createRecordingForTranscriptionTransitions(array $overrides = []): AudioRecording
{
    $tenant = Tenant::factory()->create();
    actingInWorkspace($tenant);

    $client = Client::factory()->create(['tenant_id' => $tenant->id]);
    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
    ]);

    return AudioRecording::factory()->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $dossier->id,
        'path' => 'audio/interview.mp3',
        ...$overrides,
    ]);
}
