<?php

declare(strict_types=1);

use App\Accounts\Enums\PlatformRole;
use App\AudioProcessing\Actions\NotifyUploaderOfTranscriptionCompletedAction;
use App\AudioProcessing\Contracts\StartsAudioTranscription;
use App\AudioProcessing\Notifications\AudioTranscriptionCompletedNotification;
use App\AudioProcessing\Services\Drivers\AmazonTranscribeAudioTranscriber;
use App\AudioProcessing\Services\Drivers\MockAudioTranscriber;
use App\Clients\Models\Client;
use App\DocumentProcessing\Enums\DocumentProcessingStatus;
use App\DocumentProcessing\Enums\TranscriptionStatus;
use App\Dossiers\Models\AudioRecording;
use App\Dossiers\Models\Dossier;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

test('configured transcription drivers resolve through the factory', function (string $driver, string $expectedClass): void {
    config(['transcription.driver' => $driver]);

    expect(resolve(StartsAudioTranscription::class))->toBeInstanceOf($expectedClass);
})->with([
    'mock' => ['mock', MockAudioTranscriber::class],
    'transcribe' => ['transcribe', AmazonTranscribeAudioTranscriber::class],
]);

test('unsupported transcription drivers fail closed', function (): void {
    config(['transcription.driver' => 'unsupported']);

    expect(fn (): StartsAudioTranscription => resolve(StartsAudioTranscription::class))
        ->toThrow(InvalidArgumentException::class, 'Unsupported transcription driver [unsupported].');
});

test('mock transcription cannot be resolved in production', function (): void {
    $originalEnvironment = $this->app->environment();
    $this->app->instance('env', 'production');
    config(['transcription.driver' => 'mock']);

    try {
        expect(fn (): StartsAudioTranscription => resolve(StartsAudioTranscription::class))
            ->toThrow(LogicException::class, 'Mock transcription driver cannot be used in production.');
    } finally {
        $this->app->instance('env', $originalEnvironment);
    }
});

test('mock transcriber completes a processing recording synchronously', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();

    $client = Client::factory()->create(['tenant_id' => $tenant->id]);
    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
    ]);

    $path = 'tenants/'.$tenant->id.'/dossiers/'.$dossier->id.'/audio/call.mp3';
    Storage::disk('local')->put($path, 'fake-mp3-bytes');

    $recording = AudioRecording::factory()->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $dossier->id,
        'uploaded_by' => $owner->id,
        'disk' => 'local',
        'path' => $path,
        'original_filename' => 'call.mp3',
        'mime_type' => 'audio/mpeg',
        'size_bytes' => 2048,
        'transcription_status' => TranscriptionStatus::Processing,
    ]);

    resolve(MockAudioTranscriber::class)->start($recording);

    $recording->refresh();

    expect($recording->transcription_status)->toBe(TranscriptionStatus::Completed)
        ->and($recording->transcript)->toBe('Mock transcript for call.mp3.')
        ->and($recording->segments)->toBeArray();
});

test('mock transcriber ignores stale upload revisions', function (): void {
    ['tenant' => $tenant] = provisionWorkspace();

    $client = Client::factory()->create(['tenant_id' => $tenant->id]);
    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
    ]);

    $path = 'tenants/'.$tenant->id.'/dossiers/'.$dossier->id.'/audio/call.mp3';
    Storage::disk('local')->put($path, 'fake-mp3-bytes');

    $recording = AudioRecording::factory()->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $dossier->id,
        'disk' => 'local',
        'path' => $path,
        'original_filename' => 'call.mp3',
        'mime_type' => 'audio/mpeg',
        'size_bytes' => 2048,
        // Not Processing — the optimistic update matches zero rows.
        'transcription_status' => TranscriptionStatus::Pending,
    ]);

    resolve(MockAudioTranscriber::class)->start($recording);

    expect($recording->fresh()->transcription_status)->toBe(TranscriptionStatus::Pending)
        ->and($recording->fresh()->transcript)->toBeNull();
});

test('uploader is notified when a transcript becomes ready', function (): void {
    Notification::fake();

    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();

    $client = Client::factory()->create(['tenant_id' => $tenant->id]);
    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
        'title' => 'Payroll',
    ]);

    $recording = AudioRecording::factory()->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $dossier->id,
        'uploaded_by' => $owner->id,
        'original_filename' => 'call.mp3',
        'transcription_status' => TranscriptionStatus::Completed,
    ]);

    resolve(NotifyUploaderOfTranscriptionCompletedAction::class)->handle($recording);

    Notification::assertSentTo($owner, AudioTranscriptionCompletedNotification::class);
});

test('transcript ready notification is skipped without an uploader', function (): void {
    Notification::fake();

    ['tenant' => $tenant] = provisionWorkspace();

    $client = Client::factory()->create(['tenant_id' => $tenant->id]);
    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
    ]);

    $recording = AudioRecording::factory()->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $dossier->id,
        'uploaded_by' => null,
        'transcription_status' => TranscriptionStatus::Completed,
    ]);

    resolve(NotifyUploaderOfTranscriptionCompletedAction::class)->handle($recording);

    Notification::assertNothingSent();
});

test('processing status enums expose translated labels', function (): void {
    expect(PlatformRole::Support->label())->toBe(__('Support'))
        ->and(TranscriptionStatus::Pending->label())->toBe(__('Pending'))
        ->and(TranscriptionStatus::Processing->label())->toBe(__('Processing'))
        ->and(TranscriptionStatus::Completed->label())->toBe(__('Completed'))
        ->and(TranscriptionStatus::Failed->label())->toBe(__('Failed'))
        ->and(DocumentProcessingStatus::Pending->label())->toBe(__('Pending'))
        ->and(DocumentProcessingStatus::Processing->label())->toBe(__('Processing'))
        ->and(DocumentProcessingStatus::Completed->label())->toBe(__('Completed'))
        ->and(DocumentProcessingStatus::Failed->label())->toBe(__('Failed'));
});
