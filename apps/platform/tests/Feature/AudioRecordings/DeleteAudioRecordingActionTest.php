<?php

declare(strict_types=1);

use App\Accounts\Models\User;
use App\Audit\Enums\AuditEvent;
use App\Audit\Models\Activity;
use App\Clients\Models\Client;
use App\Dossiers\Actions\AudioRecordings\DeleteAudioRecordingAction;
use App\Dossiers\Models\AudioRecording;
use App\Dossiers\Models\Dossier;
use App\Pipelines\Enums\PipelineType;
use App\Pipelines\Models\Pipeline;
use App\Tenancy\Models\Tenant;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

covers(DeleteAudioRecordingAction::class);

beforeEach(function (): void {
    Storage::fake('local');
    config()->set('filesystems.default', 'local');
});

/**
 * @return array{tenant: Tenant, owner: User, dossier: Dossier, audioRecording: AudioRecording, path: string}
 */
function createDeleteAudioRecordingFixture(): array
{
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();

    $client = Client::factory()->create(['tenant_id' => $tenant->id]);
    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
    ]);

    $path = 'tenants/'.$tenant->id.'/dossiers/'.$dossier->id.'/audio/interview.m4a';
    Storage::disk('local')->put($path, 'fake-audio-bytes');

    $audioRecording = AudioRecording::factory()->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $dossier->id,
        'uploaded_by' => $owner->id,
        'disk' => 'local',
        'path' => $path,
        'original_filename' => 'Intakegesprek_J_de_Vries.m4a',
    ]);

    return [
        'tenant' => $tenant,
        'owner' => $owner,
        'dossier' => $dossier,
        'audioRecording' => $audioRecording,
        'path' => $path,
    ];
}

test('deleting a recording removes the row, its pipelines and its stored object', function (): void {
    Queue::fake();

    ['owner' => $owner, 'audioRecording' => $audioRecording, 'path' => $path] = createDeleteAudioRecordingFixture();

    Pipeline::factory()->create([
        'tenant_id' => $audioRecording->tenant_id,
        'pipelineable_type' => $audioRecording->getMorphClass(),
        'pipelineable_id' => $audioRecording->id,
        'type' => PipelineType::AudioTranscription,
    ]);

    resolve(DeleteAudioRecordingAction::class)->handle($audioRecording, $owner);

    expect(AudioRecording::query()->whereKey($audioRecording->id)->exists())->toBeFalse()
        ->and(Pipeline::query()
            ->where('pipelineable_type', $audioRecording->getMorphClass())
            ->where('pipelineable_id', $audioRecording->id)
            ->exists())->toBeFalse()
        ->and(Storage::disk('local')->exists($path))->toBeFalse();
});

/**
 * AudioRecording has no LogsActivity, so before TASK-36.5 a deletion left no
 * trace at all — while uploads and downloads of the same file were both
 * recorded. A silent deletion is the one event a firm cannot afford to lose.
 */
test('deleting a recording is audited, matching upload and download', function (): void {
    Queue::fake();

    ['owner' => $owner, 'audioRecording' => $audioRecording] = createDeleteAudioRecordingFixture();

    $recordingId = $audioRecording->id;

    resolve(DeleteAudioRecordingAction::class)->handle($audioRecording, $owner);

    $activity = Activity::query()
        ->where('event', AuditEvent::AudioRecordingDeleted->value)
        ->where('subject_id', $recordingId)
        ->sole();

    expect($activity->causer_id)->toBe($owner->id)
        // The audit trail may name the file; the application log may not.
        // See tests/Unit/DocumentFilenameLoggingTest.php.
        ->and($activity->getProperty('original_filename'))->toBe('Intakegesprek_J_de_Vries.m4a')
        ->and($activity->description)->toBe(AuditEvent::AudioRecordingDeleted->label());
});

/**
 * The old order was blob first, then pipelines, then the row, with no
 * transaction — so a failure after the unlink left a live row pointing at
 * bytes that no longer existed.
 */
test('a rolled-back row delete leaves the stored object intact', function (): void {
    Queue::fake();

    ['owner' => $owner, 'audioRecording' => $audioRecording, 'path' => $path] = createDeleteAudioRecordingFixture();

    AudioRecording::deleting(function (): void {
        throw new RuntimeException('forced delete failure');
    });

    expect(fn () => resolve(DeleteAudioRecordingAction::class)->handle($audioRecording, $owner))
        ->toThrow(RuntimeException::class, 'forced delete failure');

    AudioRecording::flushEventListeners();

    expect(AudioRecording::query()->whereKey($audioRecording->id)->exists())->toBeTrue()
        // The whole point: the bytes are still there for the surviving row.
        ->and(Storage::disk('local')->exists($path))->toBeTrue()
        ->and(Activity::query()->where('event', AuditEvent::AudioRecordingDeleted->value)->exists())->toBeFalse();
});

/**
 * The old code discarded Storage::delete()'s return value, so an orphaned
 * object was never reported. The row is already gone at this point and must
 * stay gone — the failure is reported, not masked and not rolled back.
 */
test('a failed object delete is reported rather than discarded', function (): void {
    Queue::fake();
    Exceptions::fake();

    ['owner' => $owner, 'audioRecording' => $audioRecording] = createDeleteAudioRecordingFixture();

    // Flysystem treats deleting a missing object as a success, so the failure
    // has to come from the disk. The action takes an injected
    // FilesystemManager, so the mock is bound rather than faked on the facade.
    $disk = Mockery::mock(Filesystem::class);
    $disk->shouldReceive('delete')->once()->andReturnFalse();

    $filesystems = Mockery::mock(FilesystemManager::class);
    $filesystems->shouldReceive('disk')->with('local')->andReturn($disk);

    app()->instance(FilesystemManager::class, $filesystems);

    resolve(DeleteAudioRecordingAction::class)->handle($audioRecording, $owner);

    expect(AudioRecording::query()->whereKey($audioRecording->id)->exists())->toBeFalse();

    Exceptions::assertReported(
        fn (RuntimeException $exception): bool => $exception->getMessage()
            === 'Failed to remove the storage object for a deleted audio recording.',
    );
});
