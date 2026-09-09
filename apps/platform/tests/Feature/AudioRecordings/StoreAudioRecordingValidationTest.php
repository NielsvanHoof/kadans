<?php

declare(strict_types=1);

use App\Clients\Models\Client;
use App\DocumentProcessing\Enums\TranscriptionStatus;
use App\Dossiers\Enums\UploadPurpose;
use App\Dossiers\Http\Controllers\AudioRecordings\AudioRecordingController;
use App\Dossiers\Http\Requests\AudioRecordings\StoreAudioRecordingRequest;
use App\Dossiers\Models\AudioRecording;
use App\Dossiers\Models\Dossier;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

covers(StoreAudioRecordingRequest::class, AudioRecordingController::class);

beforeEach(function (): void {
    Storage::fake('local');
    config()->set('filesystems.default', 'local');
});

test('invalid audio uploads show a friendly field error', function (
    string $filename,
    int $kilobytes,
    string $message,
): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();
    $dossier = createDossierForValidationTest($tenant);

    $this->actingAs($owner)
        ->post(workspaceRoute('workspaces.dossiers.audio-recordings.store', $tenant, [
            'dossier' => $dossier,
        ]), [
            'audio' => stagedUploadTicket($tenant->id, $filename, UploadPurpose::AudioRecording, $kilobytes * 1024),
        ])
        ->assertSessionHasErrors([
            'audio' => $message,
        ]);

    expect(AudioRecording::query()->exists())->toBeFalse();
})->with([
    'unsupported type' => [
        'notes.pdf',
        100,
        'That file type is not supported. Upload an MP3, WAV, M4A, OGG, WebM, or MP4 file.',
    ],
    'oversized' => [
        'call.mp3',
        26_000,
        'That file is too large. The maximum size is 25 MB.',
    ],
]);

/**
 * The size that used to fail silently (TASK-42): PHP's upload_max_filesize was
 * 20M while the FormRequest advertised 25mb, so a real 21.85 MiB MP4 landed in
 * the gap and was dropped with no row, no log, and no visible error.
 *
 * Presigned uploads removed the gap itself — the bytes never pass through PHP,
 * so no ini directive can silently truncate them. Kept as a regression test
 * because the ceiling it proves is now enforced in exactly one place
 * (ClaimUploadTicketAction), and a file just under it must still get through.
 */
test('an mp4 just under the ceiling uploads instead of vanishing', function (): void {
    // Kept queued so this stays an upload test; the transcription itself is
    // covered by UploadAudioRecordingLifecycleTest.
    Queue::fake();

    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();
    $dossier = createDossierForValidationTest($tenant);

    // 22_374 KB is the 22_910_565-byte file from the original report.
    $this->actingAs($owner)
        ->post(workspaceRoute('workspaces.dossiers.audio-recordings.store', $tenant, [
            'dossier' => $dossier,
        ]), [
            'audio' => stagedUploadTicket($tenant->id, 'speech.mp4 (540p).mp4', UploadPurpose::AudioRecording, 22_374 * 1024),
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $audioRecording = AudioRecording::query()->sole();

    expect($audioRecording->original_filename)->toBe('speech.mp4 (540p).mp4')
        ->and($audioRecording->transcription_status)->toBe(TranscriptionStatus::Pending)
        ->and($audioRecording->pipelines()->count())->toBe(1);

    Storage::disk('local')->assertExists($audioRecording->path);
});

test('an unexpected failure while storing the upload surfaces as a field error, not a crash', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();
    $dossier = createDossierForValidationTest($tenant);

    $event = 'eloquent.creating: '.AudioRecording::class;
    Event::listen($event, static function (): never {
        throw new RuntimeException('Simulated database failure.');
    });

    try {
        $this->actingAs($owner)
            ->post(workspaceRoute('workspaces.dossiers.audio-recordings.store', $tenant, [
                'dossier' => $dossier,
            ]), [
                'audio' => stagedUploadTicket($tenant->id, 'call.mp3', UploadPurpose::AudioRecording, 100 * 1024),
            ])
            ->assertSessionHasErrors([
                'audio' => 'Something went wrong while uploading the audio file. Please try again.',
            ]);
    } finally {
        Event::forget($event);
    }

    expect(AudioRecording::query()->exists())->toBeFalse()
        ->and(Storage::disk('local')->allFiles())->toBeEmpty();
});

function createDossierForValidationTest(Tenant $tenant): Dossier
{
    $client = Client::factory()->create(['tenant_id' => $tenant->id]);

    return Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
    ]);
}
