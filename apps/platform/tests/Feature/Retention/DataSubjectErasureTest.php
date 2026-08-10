<?php

declare(strict_types=1);

use App\Audit\Enums\AuditEvent;
use App\Audit\Models\Activity;
use App\ClientPortal\Actions\CreateClientAccessGrantAction;
use App\ClientPortal\Models\ClientAccessGrant;
use App\Clients\Models\Client;
use App\Dossiers\Enums\DocumentRequestStatus;
use App\Dossiers\Models\DocumentRequest;
use App\Dossiers\Models\Dossier;
use App\Dossiers\Models\UploadedDocument;
use App\Platform\Actions\DataSubject\AnonymizeDataSubjectAction;
use App\Platform\Actions\DataSubject\GenerateDataSubjectExportAction;
use App\Platform\Actions\DataSubject\LogDataSubjectRequestAction;
use App\Platform\Enums\DataSubjectRequestStatus;
use App\Platform\Enums\DataSubjectRequestType;
use App\Platform\Models\DataSubjectRequest;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Erasure here is anonymisation, not deletion: the firm must keep its financial
 * administration (GDPR Art. 17(3)(b)), so what goes is the data that makes the
 * retained records attributable to a person.
 */
function eraseFixture(): array
{
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();

    $client = Client::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Marieke de Vries',
        'email' => 'marieke@example.com',
    ]);

    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
        'title' => 'Annual accounts 2025',
    ]);

    $request = resolve(LogDataSubjectRequestAction::class)
        ->handle($client, $owner, DataSubjectRequestType::Erasure);

    return ['owner' => $owner, 'tenant' => $tenant, 'client' => $client, 'dossier' => $dossier, 'request' => $request];
}

test('the client identity is replaced with a non-routable placeholder', function (): void {
    ['owner' => $owner, 'client' => $client, 'request' => $request] = eraseFixture();

    resolve(AnonymizeDataSubjectAction::class)->handle($request, $owner);

    $client->refresh();

    expect($client->name)->toBe("Erased client #{$client->id}")
        ->and($client->email)->toBe("erased-{$client->id}@erased.invalid")
        // RFC 2606 reserves .invalid, so the placeholder can never reach a mailbox.
        ->and($client->email)->toEndWith('@erased.invalid')
        ->and($request->refresh()->status)
        ->toBe(DataSubjectRequestStatus::Completed)
        ->and($request->completed_by)
        ->toBe($owner->id);
});

/**
 * The whole point of the choice: the firm stays compliant with its retention
 * duty, so the records survive even though the person is no longer named.
 */
test('dossiers and documents are retained', function (): void {
    ['owner' => $owner, 'tenant' => $tenant, 'client' => $client, 'dossier' => $dossier, 'request' => $request] = eraseFixture();

    $documentRequest = DocumentRequest::factory()->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $dossier->id,
        'status' => DocumentRequestStatus::Submitted,
    ]);

    $uploaded = UploadedDocument::factory()->create([
        'tenant_id' => $tenant->id,
        'document_request_id' => $documentRequest->id,
    ]);

    resolve(AnonymizeDataSubjectAction::class)->handle($request, $owner);

    expect(Dossier::query()->whereKey($dossier->id)->exists())->toBeTrue()
        ->and(UploadedDocument::query()->whereKey($uploaded->id)->exists())->toBeTrue()
        ->and(Client::query()->whereKey($client->id)->exists())->toBeTrue();
});

/**
 * Client activity logs its fillable attributes, so without disableLogging() the
 * anonymising save would itself write the real name and email into
 * `attribute_changes` — preserving exactly what was meant to be removed.
 */
test('the old name and email survive nowhere in the audit trail', function (): void {
    ['owner' => $owner, 'client' => $client, 'request' => $request] = eraseFixture();

    // Give the trail something to hold: an update logs old and new values.
    $client->update(['name' => 'Marieke de Vries-Jansen']);

    expect(Activity::query()->get()->toJson())->toContain('Marieke');

    resolve(AnonymizeDataSubjectAction::class)->handle($request, $owner);

    expect(Activity::query()->get()->toJson())
        ->not->toContain('Marieke')
        ->and(Activity::query()->get()->toJson())->not->toContain('marieke@example.com');
});

test('portal credentials and their device history are deleted', function (): void {
    ['owner' => $owner, 'dossier' => $dossier, 'request' => $request] = eraseFixture();

    resolve(CreateClientAccessGrantAction::class)->handle($dossier, $owner, 7);

    expect(ClientAccessGrant::query()->count())->toBe(1);

    resolve(AnonymizeDataSubjectAction::class)->handle($request, $owner);

    expect(ClientAccessGrant::query()->count())->toBe(0);
});

/**
 * An export archive is a complete copy of the person in a single file. Leaving
 * it on disk would make the erasure meaningless.
 */
test('a previously generated export archive is destroyed', function (): void {
    Storage::fake('local');
    config(['exports.disk' => 'local']);

    ['owner' => $owner, 'client' => $client, 'request' => $request] = eraseFixture();

    $exportRequest = resolve(LogDataSubjectRequestAction::class)
        ->handle($client, $owner, DataSubjectRequestType::Export);

    resolve(GenerateDataSubjectExportAction::class)->handle($exportRequest);

    $archivePath = (string) $exportRequest->refresh()->path;

    expect(Storage::disk('local')->exists($archivePath))->toBeTrue();

    resolve(AnonymizeDataSubjectAction::class)->handle($request, $owner);

    expect(Storage::disk('local')->exists($archivePath))->toBeFalse()
        ->and($exportRequest->refresh()->path)->toBeNull()
        ->and($exportRequest->isDownloadable())->toBeFalse();
});

test('the client is no longer findable by their old name', function (): void {
    ['owner' => $owner, 'client' => $client, 'request' => $request] = eraseFixture();

    expect(Client::search('Marieke')->get())->toHaveCount(1);

    resolve(AnonymizeDataSubjectAction::class)->handle($request, $owner);

    expect(Client::search('Marieke')->get())
        ->toBeEmpty()
        ->and(Client::search('marieke@example.com')->get())
        ->toBeEmpty();
});

/**
 * The firm's proof that it complied. Recorded against the request rather than
 * the client, so scrubbing the client's trail cannot remove it.
 */
test('the erasure itself is audited and survives the erasure', function (): void {
    ['owner' => $owner, 'client' => $client, 'request' => $request] = eraseFixture();

    resolve(AnonymizeDataSubjectAction::class)->handle($request, $owner);

    $activity = Activity::query()
        ->where('event', AuditEvent::DataSubjectErasureCompleted->value)
        ->latest('id')
        ->first();

    expect($activity)->not->toBeNull()
        ->and($activity->subject_id)->toBe($request->id)
        ->and($activity->causer_id)->toBe($owner->id)
        ->and($activity->getProperty('client_id'))->toBe($client->id)
        ->and($activity->getProperty('dossiers_retained'))->toBe(1);
});

/**
 * Activity rows are immutable by design (`Activity::booted()` throws on update),
 * so redacting the client's IP and user agent in place is impossible. Deletion
 * is the sanctioned operation, and it is what the scheduled retention purge
 * uses too.
 */
test('the trail carrying the client request context is deleted, not edited', function (): void {
    ['owner' => $owner, 'client' => $client, 'dossier' => $dossier, 'request' => $request] = eraseFixture();

    Activity::query()->create([
        'log_name' => config('activitylog.default_log_name'),
        'description' => 'Client portal accessed',
        'event' => AuditEvent::ClientPortalAccessed->value,
        'subject_type' => $dossier->getMorphClass(),
        'subject_id' => $dossier->id,
        'tenant_id' => $client->tenant_id,
        'properties' => ['ip' => '203.0.113.9', 'user_agent' => 'Mozilla/5.0'],
    ]);

    resolve(AnonymizeDataSubjectAction::class)->handle($request, $owner);

    expect(Activity::query()->get()->toJson())
        ->not->toContain('203.0.113.9')
        ->not->toContain('Mozilla/5.0');

    // The erasure entry itself is recorded against the request, so it survives.
    expect(Activity::query()
        ->where('event', AuditEvent::DataSubjectErasureCompleted->value)
        ->exists())->toBeTrue();
});

test('an activity row cannot be edited, only removed', function (): void {
    ['client' => $client, 'dossier' => $dossier] = eraseFixture();

    $activity = Activity::query()->create([
        'log_name' => config('activitylog.default_log_name'),
        'description' => 'Client portal accessed',
        'event' => AuditEvent::ClientPortalAccessed->value,
        'subject_type' => $dossier->getMorphClass(),
        'subject_id' => $dossier->id,
        'tenant_id' => $client->tenant_id,
        'properties' => ['ip' => '203.0.113.9'],
    ]);

    expect(fn () => $activity->forceFill(['properties' => []])->save())
        ->toThrow(RuntimeException::class, 'Audit log records are immutable.');
});

test('an erasure cannot be run twice or against an export request', function (): void {
    ['owner' => $owner, 'client' => $client, 'request' => $request] = eraseFixture();

    $action = resolve(AnonymizeDataSubjectAction::class);
    $action->handle($request, $owner);

    expect(fn () => $action->handle($request->refresh(), $owner))
        ->toThrow(ValidationException::class);

    $exportRequest = DataSubjectRequest::factory()->create([
        'tenant_id' => $client->tenant_id,
        'client_id' => $client->id,
    ]);

    expect(fn () => $action->handle($exportRequest, $owner))
        ->toThrow(RuntimeException::class);
});
