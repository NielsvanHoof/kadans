<?php

declare(strict_types=1);

use App\Accounts\Models\User;
use App\AudioProcessing\Actions\MarkTranscribeJobFailedAction;
use App\Audit\Enums\AuditEvent;
use App\Audit\Models\Activity;
use App\ClientPortal\Models\ClientAccessGrant;
use App\ClientPortal\Notifications\ClientPortalReminderNotification;
use App\Clients\Models\Client;
use App\DocumentProcessing\Enums\TranscriptionStatus;
use App\Dossiers\Actions\DocumentRequests\ReviewDocumentRequestAction;
use App\Dossiers\Actions\MarkDossierExportFailedAction;
use App\Dossiers\Enums\DocumentRequestStatus;
use App\Dossiers\Enums\DossierReminderSource;
use App\Dossiers\Models\AudioRecording;
use App\Dossiers\Models\DocumentRequest;
use App\Dossiers\Models\Dossier;
use App\Dossiers\Models\DossierExport;
use App\Pipelines\Actions\DeletePipelineAction;
use App\Pipelines\Enums\PipelineStatus;
use App\Pipelines\Models\Pipeline;
use App\Platform\Support\SupportSession;
use App\Tenancy\Enums\Role;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

/**
 * Emission coverage for audit events on branches the domain suites do not
 * otherwise reach — mostly failure paths, which are exactly the ones that can
 * quietly stop firing without any test noticing.
 *
 * AuditEventCoverageTest is what forces this file to keep up: it fails when any
 * declared event has no test naming it.
 */
function latestAuditEvent(AuditEvent $event): ?Activity
{
    return Activity::query()
        ->where('event', $event->value)
        ->latest('id')
        ->first();
}

test('rejecting a document request is audited', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();

    $client = Client::factory()->create(['tenant_id' => $tenant->id]);
    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
    ]);
    $documentRequest = DocumentRequest::factory()->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $dossier->id,
        'status' => DocumentRequestStatus::Submitted,
    ]);

    resolve(ReviewDocumentRequestAction::class)->handle(
        $documentRequest,
        $owner,
        DocumentRequestStatus::Rejected,
        'The statement is unreadable.',
    );

    $activity = latestAuditEvent(AuditEvent::DocumentRequestRejected);

    expect($activity)->not->toBeNull()
        ->and($activity->tenant_id)->toBe($tenant->id)
        ->and($activity->subject_id)->toBe($documentRequest->id);
});

test('a failed dossier export is audited', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();

    $client = Client::factory()->create(['tenant_id' => $tenant->id]);
    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
    ]);
    $export = DossierExport::factory()->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $dossier->id,
        'requested_by' => $owner->id,
    ]);

    resolve(MarkDossierExportFailedAction::class)->handle(
        $export,
        new RuntimeException('The archive could not be written.'),
    );

    $activity = latestAuditEvent(AuditEvent::DossierExportFailed);

    expect($activity)->not->toBeNull()
        ->and($activity->subject_id)->toBe($export->id)
        ->and($activity->getProperty('error'))->toContain('archive could not be written');
});

test('a reminder that fails to send is audited against the dossier', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();

    $client = Client::factory()->create(['tenant_id' => $tenant->id]);
    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
    ]);
    $grant = ClientAccessGrant::factory()->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $dossier->id,
        'created_by' => $owner->id,
    ]);

    Event::dispatch(new NotificationFailed(
        $client,
        new ClientPortalReminderNotification(
            grantId: $grant->id,
            dossier: $dossier,
            portalUrl: 'https://app.test/portal/access/token',
            expiresAt: now()->addDay(),
            firmName: $tenant->name,
            source: DossierReminderSource::Scheduled,
        ),
        'mail',
    ));

    $activity = latestAuditEvent(AuditEvent::DossierReminderFailed);

    expect($activity)->not->toBeNull()
        ->and($activity->subject_id)->toBe($dossier->id)
        ->and($activity->getProperty('source'))->toBe(DossierReminderSource::Scheduled->value);
});

/**
 * The reminder listener redacts before it writes: the notification carries a
 * live portal URL, and LogAuditActivityAction drops any `portal_url` property.
 */
test('a failed reminder audit entry carries no portal link', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();

    $client = Client::factory()->create(['tenant_id' => $tenant->id]);
    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
    ]);
    $grant = ClientAccessGrant::factory()->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $dossier->id,
        'created_by' => $owner->id,
    ]);

    Event::dispatch(new NotificationFailed(
        $client,
        new ClientPortalReminderNotification(
            grantId: $grant->id,
            dossier: $dossier,
            portalUrl: 'https://app.test/portal/access/super-secret-token',
            expiresAt: now()->addDay(),
            firmName: $tenant->name,
            source: DossierReminderSource::Manual,
        ),
        'mail',
    ));

    $activity = latestAuditEvent(AuditEvent::DossierReminderFailed);

    expect(json_encode($activity?->properties))->not->toContain('super-secret-token');
});

test('deleting a terminal pipeline is audited before the row disappears', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();

    $pipeline = Pipeline::factory()->create([
        'tenant_id' => $tenant->id,
        'status' => PipelineStatus::Failed,
    ]);

    resolve(DeletePipelineAction::class)->handle($pipeline, $owner);

    $activity = latestAuditEvent(AuditEvent::PipelineDeleted);

    expect($activity)->not->toBeNull()
        ->and($activity->subject_id)->toBe($pipeline->id)
        ->and($activity->causer_id)->toBe($owner->id)
        ->and(Pipeline::query()->whereKey($pipeline->id)->exists())->toBeFalse();
});

test('downloading an audio recording is audited', function (): void {
    Storage::fake('local');

    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();

    $client = Client::factory()->create(['tenant_id' => $tenant->id]);
    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
    ]);
    $recording = AudioRecording::factory()->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $dossier->id,
        'disk' => 'local',
        'path' => 'audio/meeting.mp3',
    ]);

    Storage::disk('local')->put('audio/meeting.mp3', 'fake-audio-bytes');

    $this->actingAs($owner)
        // Media routes sit behind RequirePassword, like every document route.
        ->withSession(['auth.password_confirmed_at' => Date::now()->timestamp])
        ->get(workspaceRoute('workspaces.audio-recordings.download', $tenant, [
            'audioRecording' => $recording,
        ]))
        ->assertOk();

    $activity = latestAuditEvent(AuditEvent::AudioRecordingDownloaded);

    expect($activity)->not->toBeNull()
        ->and($activity->subject_id)->toBe($recording->id)
        ->and($activity->causer_id)->toBe($owner->id);
});

test('a terminal transcription failure is audited', function (): void {
    ['tenant' => $tenant] = provisionWorkspace();

    $client = Client::factory()->create(['tenant_id' => $tenant->id]);
    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
    ]);
    $recording = AudioRecording::factory()->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $dossier->id,
        'transcription_status' => TranscriptionStatus::Processing,
        'transcribe_job_id' => 'audio-77-cafebabe',
    ]);

    resolve(MarkTranscribeJobFailedAction::class)->handle(
        $recording,
        'audio-77-cafebabe',
        'Amazon Transcribe reported an unsupported media format.',
        'FAILED',
    );

    $activity = latestAuditEvent(AuditEvent::AudioTranscriptionFailed);

    expect($activity)->not->toBeNull()
        ->and($activity->subject_id)->toBe($recording->id)
        ->and($activity->getProperty('transcribe_job_id'))->toBe('audio-77-cafebabe')
        ->and($recording->fresh()->transcription_status)->toBe(TranscriptionStatus::Failed);
});

/**
 * A stale callback must neither change state nor write a trail entry: an audit
 * log that records events which did not happen is worse than none.
 */
test('a stale transcription failure is discarded without an audit entry', function (): void {
    ['tenant' => $tenant] = provisionWorkspace();

    $client = Client::factory()->create(['tenant_id' => $tenant->id]);
    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
    ]);
    $recording = AudioRecording::factory()->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $dossier->id,
        'transcription_status' => TranscriptionStatus::Processing,
        'transcribe_job_id' => 'audio-77-current',
    ]);

    resolve(MarkTranscribeJobFailedAction::class)->handle(
        $recording,
        'audio-77-superseded',
        'Late failure for a replaced upload.',
        'FAILED',
    );

    expect(latestAuditEvent(AuditEvent::AudioTranscriptionFailed))->toBeNull()
        ->and($recording->fresh()->transcription_status)->toBe(TranscriptionStatus::Processing);
});

/**
 * The only route that actually reaches `clearIfExpired()`: the support user is
 * also a firm member, so InitializeTenantFromRoute's `canAccessTenant()` check
 * passes on the membership and EnsureValidTenantMembership gets to run.
 *
 * For a support user with no membership the expiry is never cleared or audited
 * (that middleware 404s first) — PlatformSupportTest pins that gap explicitly.
 */
test('an expired support session is audited when the middleware reaches it', function (): void {
    ['tenant' => $tenant] = provisionWorkspace();

    $support = User::factory()->platformSupport()->create();
    workspaceMember($tenant, Role::ReadOnly, $support);

    $this->actingAs($support)
        ->post(route('platform.support.store', $tenant), ['reason' => 'Investigating an upload failure'])
        ->assertRedirect();

    $payload = session(SupportSession::SESSION_KEY);
    $payload['expires_at'] = now()->subMinute()->toIso8601String();

    $this->withSession([SupportSession::SESSION_KEY => $payload])
        ->get(workspaceRoute('workspaces.dashboard', $tenant))
        ->assertOk();

    $activity = latestAuditEvent(AuditEvent::SupportSessionExpired);

    expect($activity)->not->toBeNull()
        ->and($activity->causer_id)->toBe($support->id)
        ->and($activity->subject_id)->toBe($tenant->id);
});

test('workspace members without permission cannot reach the audio download', function (): void {
    ['tenant' => $tenant] = provisionWorkspace();
    $member = workspaceMember($tenant, Role::ReadOnly);

    $client = Client::factory()->create(['tenant_id' => $tenant->id]);
    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
    ]);
    $recording = AudioRecording::factory()->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $dossier->id,
    ]);

    $this->actingAs($member)
        ->withSession(['auth.password_confirmed_at' => Date::now()->timestamp])
        ->get(workspaceRoute('workspaces.audio-recordings.download', $tenant, [
            'audioRecording' => $recording,
        ]))
        ->assertForbidden();

    expect(latestAuditEvent(AuditEvent::AudioRecordingDownloaded))->toBeNull();
});
