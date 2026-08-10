<?php

declare(strict_types=1);

use App\Clients\Models\Client;
use App\Dossiers\Http\Controllers\AudioRecordingController;
use App\Dossiers\Http\Requests\AudioRecordings\StoreAudioRecordingRequest;
use App\Dossiers\Models\AudioRecording;
use App\Dossiers\Models\Dossier;
use App\Tenancy\Models\Tenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

covers(StoreAudioRecordingRequest::class, AudioRecordingController::class);

beforeEach(function (): void {
    Storage::fake('local');
    config()->set('filesystems.default', 'local');
});

test('invalid audio uploads show a friendly field error', function (
    string $filename,
    int $kilobytes,
    string $mime,
    string $message,
): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();
    $dossier = createDossierForValidationTest($tenant);

    $this->actingAs($owner)
        ->post(workspaceRoute('workspaces.dossiers.audio-recordings.store', $tenant, [
            'dossier' => $dossier,
        ]), [
            'audio' => UploadedFile::fake()->create($filename, $kilobytes, $mime),
        ])
        ->assertSessionHasErrors([
            'audio' => $message,
        ]);

    expect(AudioRecording::query()->exists())->toBeFalse();
})->with([
    'unsupported type' => [
        'notes.pdf',
        100,
        'application/pdf',
        'That file type is not supported. Upload an MP3, WAV, M4A, OGG, WebM, or MP4 file.',
    ],
    'oversized' => [
        'call.mp3',
        26_000,
        'audio/mpeg',
        'That file is too large. The maximum size is 25 MB.',
    ],
]);

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
                'audio' => UploadedFile::fake()->create('call.mp3', 100, 'audio/mpeg'),
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
