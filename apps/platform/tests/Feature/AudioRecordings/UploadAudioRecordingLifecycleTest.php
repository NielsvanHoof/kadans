<?php

declare(strict_types=1);

use App\AudioProcessing\Jobs\ProcessAudioRecordingJob;
use App\Clients\Models\Client;
use App\DocumentProcessing\Enums\TranscriptionStatus;
use App\Dossiers\Actions\AudioRecordings\UploadAudioRecordingAction;
use App\Dossiers\Models\AudioRecording;
use App\Dossiers\Models\Dossier;
use App\Tenancy\Models\Tenant;
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
            && $job->expectedPath === $audioRecording->path,
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

function createDossierForAudioLifecycleTest(): Dossier
{
    $tenant = Tenant::factory()->create();
    $tenant->makeCurrent();

    $client = Client::factory()->create(['tenant_id' => $tenant->id]);

    return Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
    ]);
}
