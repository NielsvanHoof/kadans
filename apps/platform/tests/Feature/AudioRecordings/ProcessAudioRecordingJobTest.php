<?php

declare(strict_types=1);

use App\Accounts\Models\User;
use App\AudioProcessing\Actions\NotifyUploaderOfTranscriptionCompletedAction;
use App\AudioProcessing\Contracts\TranscribesAudio;
use App\AudioProcessing\Jobs\ProcessAudioRecordingJob;
use App\AudioProcessing\Notifications\AudioTranscriptionCompletedNotification;
use App\AudioProcessing\Services\TranscriptionOrchestrator;
use App\Audit\Actions\LogAuditActivityAction;
use App\Audit\Enums\AuditEvent;
use App\Audit\Models\Activity;
use App\Clients\Models\Client;
use App\DocumentProcessing\Actions\Pipelines\PipelineStateAction;
use App\DocumentProcessing\Enums\TranscriptionStatus;
use App\Dossiers\Models\AudioRecording;
use App\Dossiers\Models\Dossier;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('local');
    config(['transcription.driver' => 'mock']);
});

test('processing job transcribes mock text and marks the recording completed', function (): void {
    $recording = createAudioRecordingForProcessingTest();

    new ProcessAudioRecordingJob($recording->id, $recording->path)->handle(
        resolve(TranscriptionOrchestrator::class),
        resolve(LogAuditActivityAction::class),
        resolve(PipelineStateAction::class),
        resolve(NotifyUploaderOfTranscriptionCompletedAction::class),
    );

    $recording->refresh();

    expect($recording->transcription_status)->toBe(TranscriptionStatus::Completed)
        ->and($recording->transcript)->toContain($recording->original_filename)
        ->and($recording->transcription_error)->toBeNull()
        ->and($recording->transcription_finished_at)->not->toBeNull();
});

test('processing job notifies the uploader when the transcript is ready', function (): void {
    Notification::fake();

    $uploader = User::factory()->create();
    $recording = createAudioRecordingForProcessingTest([
        'uploaded_by' => $uploader->id,
    ]);

    new ProcessAudioRecordingJob($recording->id, $recording->path)->handle(
        resolve(TranscriptionOrchestrator::class),
        resolve(LogAuditActivityAction::class),
        resolve(PipelineStateAction::class),
        resolve(NotifyUploaderOfTranscriptionCompletedAction::class),
    );

    Notification::assertSentTo(
        $uploader,
        AudioTranscriptionCompletedNotification::class,
        fn (AudioTranscriptionCompletedNotification $notification): bool => $notification->audioRecordingId === $recording->id
            && $notification->originalFilename === $recording->original_filename,
    );
});

test('processing job does not notify when the recording has no known uploader', function (): void {
    Notification::fake();

    $recording = createAudioRecordingForProcessingTest(['uploaded_by' => null]);

    new ProcessAudioRecordingJob($recording->id, $recording->path)->handle(
        resolve(TranscriptionOrchestrator::class),
        resolve(LogAuditActivityAction::class),
        resolve(PipelineStateAction::class),
        resolve(NotifyUploaderOfTranscriptionCompletedAction::class),
    );

    Notification::assertNothingSent();
});

test('processing job is idempotent when the recording is already completed', function (): void {
    $recording = createAudioRecordingForProcessingTest([
        'transcription_status' => TranscriptionStatus::Completed,
        'transcript' => 'Already transcribed',
        'transcription_finished_at' => now(),
    ]);

    new ProcessAudioRecordingJob($recording->id, $recording->path)->handle(
        resolve(TranscriptionOrchestrator::class),
        resolve(LogAuditActivityAction::class),
        resolve(PipelineStateAction::class),
        resolve(NotifyUploaderOfTranscriptionCompletedAction::class),
    );

    $recording->refresh();

    expect($recording->transcript)->toBe('Already transcribed')
        ->and($recording->transcription_status)->toBe(TranscriptionStatus::Completed)
        ->and(Activity::query()
            ->where('event', AuditEvent::AudioTranscriptionStarted->value)
            ->where('subject_id', $recording->id)
            ->exists())
        ->toBeFalse();
});

test('processing job does not restart a recording another worker already claimed', function (): void {
    $recording = createAudioRecordingForProcessingTest([
        'transcription_status' => TranscriptionStatus::Processing,
        'transcription_started_at' => now(),
    ]);

    $transcriber = Mockery::mock(TranscribesAudio::class);
    $transcriber->shouldNotReceive('transcribe');

    new ProcessAudioRecordingJob($recording->id, $recording->path)->handle(
        new TranscriptionOrchestrator($transcriber, resolve(LogAuditActivityAction::class)),
        resolve(LogAuditActivityAction::class),
        resolve(PipelineStateAction::class),
        resolve(NotifyUploaderOfTranscriptionCompletedAction::class),
    );

    $recording->refresh();

    expect($recording->transcription_status)->toBe(TranscriptionStatus::Processing)
        ->and(Activity::query()
            ->where('event', AuditEvent::AudioTranscriptionStarted->value)
            ->where('subject_id', $recording->id)
            ->exists())
        ->toBeFalse();
});

test('processing job ignores an upload revision that has been replaced', function (): void {
    $recording = createAudioRecordingForProcessingTest();
    $staleJob = new ProcessAudioRecordingJob($recording->id, $recording->path);

    $recording->forceFill(['path' => 'audio/replacement.mp3'])->save();

    $transcriber = Mockery::mock(TranscribesAudio::class);
    $transcriber->shouldNotReceive('transcribe');

    $staleJob->handle(
        new TranscriptionOrchestrator($transcriber, resolve(LogAuditActivityAction::class)),
        resolve(LogAuditActivityAction::class),
        resolve(PipelineStateAction::class),
        resolve(NotifyUploaderOfTranscriptionCompletedAction::class),
    );

    $recording->refresh();

    expect($recording->transcription_status)->toBe(TranscriptionStatus::Pending)
        ->and($recording->path)->toBe('audio/replacement.mp3');
});

/**
 * @param  array<string, mixed>  $overrides
 */
function createAudioRecordingForProcessingTest(array $overrides = []): AudioRecording
{
    $tenant = Tenant::factory()->create();
    $tenant->makeCurrent();

    $client = Client::factory()->create(['tenant_id' => $tenant->id]);
    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
    ]);

    $path = 'tenants/'.$tenant->id.'/dossiers/'.$dossier->id.'/audio/call.mp3';
    Storage::disk('local')->put($path, 'fake-mp3-bytes');

    return AudioRecording::factory()->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $dossier->id,
        'disk' => 'local',
        'path' => $path,
        'original_filename' => 'call.mp3',
        'mime_type' => 'audio/mpeg',
        'size_bytes' => 2048,
        'transcription_status' => TranscriptionStatus::Pending,
        ...$overrides,
    ]);
}
