<?php

declare(strict_types=1);

use App\Audit\Enums\AuditEvent;
use App\Audit\Models\Activity;
use App\Clients\Models\Client;
use App\Dossiers\Enums\DossierStatus;
use App\Dossiers\Models\AudioRecording;
use App\Dossiers\Models\Dossier;
use App\Tenancy\Enums\Role;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/**
 * The staff audio delete route had no test of any kind before TASK-36.5, which
 * is how it kept an untransactional, unaudited destroy() through a security
 * audit and a P1/P2 remediation pass.
 */
beforeEach(function (): void {
    Storage::fake('local');
    config()->set('filesystems.default', 'local');
    Queue::fake();
});

/**
 * @return array{tenant: Tenant, dossier: Dossier, audioRecording: AudioRecording, path: string}
 */
function destroyAudioRecordingFixture(DossierStatus $status = DossierStatus::InReview): array
{
    ['tenant' => $tenant] = provisionWorkspace();

    $client = Client::factory()->create(['tenant_id' => $tenant->id]);
    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
        'status' => $status,
    ]);

    $path = 'tenants/'.$tenant->id.'/dossiers/'.$dossier->id.'/audio/interview.m4a';
    Storage::disk('local')->put($path, 'fake-audio-bytes');

    $audioRecording = AudioRecording::factory()->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $dossier->id,
        'disk' => 'local',
        'path' => $path,
    ]);

    return ['tenant' => $tenant, 'dossier' => $dossier, 'audioRecording' => $audioRecording, 'path' => $path];
}

test('an owner can delete an audio recording, and it is audited', function (): void {
    ['tenant' => $tenant, 'audioRecording' => $audioRecording, 'path' => $path] = destroyAudioRecordingFixture();

    $owner = workspaceMember($tenant, Role::Owner);
    $recordingId = $audioRecording->id;

    $this->actingAs($owner)
        ->delete(workspaceRoute('workspaces.audio-recordings.destroy', $tenant, [
            'audioRecording' => $audioRecording->id,
        ]))
        ->assertRedirect();

    actingInWorkspace($tenant);

    expect(AudioRecording::query()->whereKey($recordingId)->exists())->toBeFalse()
        ->and(Storage::disk('local')->exists($path))->toBeFalse()
        ->and(Activity::query()
            ->where('event', AuditEvent::AudioRecordingDeleted->value)
            ->where('subject_id', $recordingId)
            ->where('causer_id', $owner->id)
            ->exists())->toBeTrue();
});

test('a read-only member cannot delete an audio recording', function (): void {
    ['tenant' => $tenant, 'audioRecording' => $audioRecording, 'path' => $path] = destroyAudioRecordingFixture();

    $readOnly = workspaceMember($tenant, Role::ReadOnly);
    $recordingId = $audioRecording->id;

    $this->actingAs($readOnly)
        ->delete(workspaceRoute('workspaces.audio-recordings.destroy', $tenant, [
            'audioRecording' => $audioRecording->id,
        ]))
        ->assertForbidden();

    actingInWorkspace($tenant);

    // Denied means nothing moved: not the row, not the bytes, not the trail.
    expect(AudioRecording::query()->whereKey($recordingId)->exists())->toBeTrue()
        ->and(Storage::disk('local')->exists($path))->toBeTrue()
        ->and(Activity::query()->where('event', AuditEvent::AudioRecordingDeleted->value)->exists())->toBeFalse();
});

/**
 * AudioRecordingPolicy::delete() also requires an unlocked dossier, so a
 * completed dossier is closed to deletion even for an owner.
 */
test('nobody can delete a recording on a completed dossier', function (): void {
    ['tenant' => $tenant, 'audioRecording' => $audioRecording, 'path' => $path] =
        destroyAudioRecordingFixture(DossierStatus::Completed);

    $owner = workspaceMember($tenant, Role::Owner);
    $recordingId = $audioRecording->id;

    $this->actingAs($owner)
        ->delete(workspaceRoute('workspaces.audio-recordings.destroy', $tenant, [
            'audioRecording' => $audioRecording->id,
        ]))
        ->assertForbidden();

    actingInWorkspace($tenant);

    expect(AudioRecording::query()->whereKey($recordingId)->exists())->toBeTrue()
        ->and(Storage::disk('local')->exists($path))->toBeTrue();
});
