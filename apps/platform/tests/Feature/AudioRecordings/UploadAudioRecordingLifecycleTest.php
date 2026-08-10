<?php

declare(strict_types=1);

use App\Accounts\Models\User;
use App\AudioProcessing\Jobs\ProcessAudioRecordingJob;
use App\Audit\Enums\AuditEvent;
use App\Audit\Models\Activity;
use App\Clients\Models\Client;
use App\DocumentProcessing\Enums\TranscriptionStatus;
use App\Dossiers\Actions\AudioRecordings\UploadAudioRecordingAction;
use App\Dossiers\Models\AudioRecording;
use App\Dossiers\Models\Dossier;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('local');
    Queue::fake();
    config()->set('filesystems.default', 'local');
});

test('uploading an audio recording stores the file, creates a pipeline, and dispatches the transcription job', function (): void {
    $dossier = createDossierForAudioLifecycleTest();
    $upload = resolve(UploadAudioRecordingAction::class);

    $audioRecording = $upload->handle(
        $dossier,
        UploadedFile::fake()->create('call.mp3', 100, 'audio/mpeg'),
    );

    expect($audioRecording->original_filename)->toBe('call.mp3')
        ->and($audioRecording->transcription_status)->toBe(TranscriptionStatus::Pending)
        ->and($audioRecording->pipelines()->count())->toBe(1);

    Storage::disk('local')->assertExists($audioRecording->path);

    Queue::assertPushed(
        ProcessAudioRecordingJob::class,
        fn (ProcessAudioRecordingJob $job): bool => $job->audioRecordingId === $audioRecording->id
            && $job->expectedPath === $audioRecording->path
            // Off the default queue — a slow transcription must not stall unrelated jobs.
            && $job->queue === 'transcription',
    );
});

test('a failed upload removes the newly stored file', function (): void {
    $dossier = createDossierForAudioLifecycleTest();
    $upload = resolve(UploadAudioRecordingAction::class);

    $event = 'eloquent.creating: '.AudioRecording::class;
    Event::listen($event, static function (): never {
        throw new RuntimeException('Simulated database failure.');
    });

    try {
        expect(fn () => $upload->handle(
            $dossier,
            UploadedFile::fake()->create('call.mp3', 100, 'audio/mpeg'),
        ))->toThrow(RuntimeException::class, 'Simulated database failure.');
    } finally {
        Event::forget($event);
    }

    expect(AudioRecording::query()->exists())->toBeFalse()
        ->and(Storage::disk('local')->allFiles())->toBeEmpty();
});

test('uploading an audio recording writes a tenant scoped audit entry', function (): void {
    $dossier = createDossierForAudioLifecycleTest();
    $uploader = User::factory()->create();

    $audioRecording = resolve(UploadAudioRecordingAction::class)->handle(
        $dossier,
        UploadedFile::fake()->create('call.mp3', 100, 'audio/mpeg'),
        $uploader,
    );

    $activity = Activity::query()
        ->where('event', AuditEvent::AudioRecordingUploaded->value)
        ->sole();

    expect($activity->subject_id)->toBe($audioRecording->id)
        ->and($activity->subject_type)->toBe('audio_recording')
        ->and($activity->tenant_id)->toBe($dossier->tenant_id)
        ->and($activity->causer_id)->toBe($uploader->id)
        ->and($activity->getProperty('original_filename'))->toBe('call.mp3');
});

test('an audio upload rolls back database and storage when its audit record fails', function (): void {
    $dossier = createDossierForAudioLifecycleTest();
    $upload = resolve(UploadAudioRecordingAction::class);

    $event = 'eloquent.creating: '.Activity::class;
    Event::listen($event, static function (Activity $activity): void {
        throw_if(
            $activity->event === AuditEvent::AudioRecordingUploaded->value,
            RuntimeException::class,
            'Simulated audit failure.',
        );
    });

    try {
        expect(fn () => $upload->handle(
            $dossier,
            UploadedFile::fake()->create('call.mp3', 100, 'audio/mpeg'),
        ))->toThrow(RuntimeException::class, 'Simulated audit failure.');
    } finally {
        Event::forget($event);
    }

    expect(AudioRecording::query()->exists())->toBeFalse()
        ->and(Storage::disk('local')->allFiles())->toBeEmpty();
});

function createDossierForAudioLifecycleTest(): Dossier
{
    // provisionWorkspace sets the current tenant for scoped queries and audit logs.
    ['tenant' => $tenant] = provisionWorkspace();

    $client = Client::factory()->create(['tenant_id' => $tenant->id]);

    return Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
    ]);
}
