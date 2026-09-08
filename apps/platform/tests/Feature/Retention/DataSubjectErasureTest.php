<?php

declare(strict_types=1);

use App\Audit\Enums\AuditEvent;
use App\Audit\Models\Activity;
use App\ClientPortal\Actions\Access\CreateClientAccessGrantAction;
use App\ClientPortal\Models\ClientAccessGrant;
use App\Clients\Models\Client;
use App\Dossiers\Enums\DocumentRequestStatus;
use App\Dossiers\Models\DocumentRequest;
use App\Dossiers\Models\Dossier;
use App\Dossiers\Models\UploadedDocument;
use App\Platform\Actions\DataSubject\AnonymizeDataSubjectAction;
use App\Platform\Actions\DataSubject\GenerateDataSubjectExportAction;
use App\Platform\Actions\DataSubject\LogDataSubjectRequestAction;
use App\Platform\Actions\DataSubject\RefuseDataSubjectRequestAction;
use App\Platform\Enums\DataSubjectRequestStatus;
use App\Platform\Enums\DataSubjectRequestType;
use App\Platform\Models\DataSubjectRequest;
use App\Tenancy\Enums\Role;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
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

/*
|--------------------------------------------------------------------------
| Racing the guarded transition
|--------------------------------------------------------------------------
|
| The test below this block refreshes the model between calls, so the cheap
| pre-check catches the second attempt. That is the sequential case.
|
| These tests hydrate TWO separate instances of the same row, which is what
| two concurrent POSTs actually hold: each read the request before either
| wrote to it, so both copies say Pending. Reusing one instance would not
| reproduce the bug, because the old code mutated that instance in place.
|
| Before TASK-36.3 the guard was `$request->status->isOpen()` on the caller's
| copy, checked outside the transaction and never re-read inside it, so both
| calls passed and both erased — leaving two DataSubjectErasureCompleted
| entries with different completed_by.
|
*/

test('a double submit erases once and records one completed entry', function (): void {
    ['owner' => $owner, 'tenant' => $tenant, 'request' => $request] = eraseFixture();

    $secondOperator = workspaceMember($tenant, Role::Owner);
    actingInWorkspace($tenant);

    // Two independent reads of the same row — one per in-flight request.
    $firstRead = DataSubjectRequest::query()->findOrFail($request->getKey());
    $secondRead = DataSubjectRequest::query()->findOrFail($request->getKey());

    expect($firstRead->status)->toBe(DataSubjectRequestStatus::Pending)
        ->and($secondRead->status)->toBe(DataSubjectRequestStatus::Pending);

    $action = resolve(AnonymizeDataSubjectAction::class);

    $action->handle($firstRead, $owner);

    // The loser still believes the request is open. Only a re-read under the
    // row lock can tell it otherwise.
    expect($secondRead->status)->toBe(DataSubjectRequestStatus::Pending)
        ->and(fn () => $action->handle($secondRead, $secondOperator))
        ->toThrow(ValidationException::class);

    actingInWorkspace($tenant);

    expect(
        Activity::query()
            ->where('event', AuditEvent::DataSubjectErasureCompleted->value)
            ->count(),
    )->toBe(1)
        // The first operator owns the erasure; the loser must not overwrite it.
        ->and($request->refresh()->completed_by)->toBe($owner->id);
});

test('a refusal cannot win a race against a completed fulfilment', function (): void {
    ['owner' => $owner, 'tenant' => $tenant, 'request' => $request] = eraseFixture();

    $secondOperator = workspaceMember($tenant, Role::Owner);
    actingInWorkspace($tenant);

    $fulfilRead = DataSubjectRequest::query()->findOrFail($request->getKey());
    $refuseRead = DataSubjectRequest::query()->findOrFail($request->getKey());

    resolve(AnonymizeDataSubjectAction::class)->handle($fulfilRead, $owner);

    expect($refuseRead->status)->toBe(DataSubjectRequestStatus::Pending)
        ->and(fn () => resolve(RefuseDataSubjectRequestAction::class)
            ->handle($refuseRead, $secondOperator, 'Retention obligation under Art. 17(3)(b).'))
        ->toThrow(ValidationException::class);

    $request->refresh();

    // A request recorded as both Completed and Refused would make the trail
    // unreadable: two contradictory answers to one legal request.
    expect($request->status)->toBe(DataSubjectRequestStatus::Completed)
        ->and($request->refused_by)->toBeNull()
        ->and($request->refusal_reason)->toBeNull();
});

test('the erasure locks the request row before it writes anything, archives included', function (): void {
    Storage::fake('local');
    config(['exports.disk' => 'local']);

    ['owner' => $owner, 'client' => $client, 'request' => $request] = eraseFixture();

    // A sibling archive, so the archive-clearing write is in play.
    $exportRequest = resolve(LogDataSubjectRequestAction::class)
        ->handle($client, $owner, DataSubjectRequestType::Export);
    resolve(GenerateDataSubjectExportAction::class)->handle($exportRequest);

    /** @var list<string> $queries */
    $queries = [];

    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    resolve(AnonymizeDataSubjectAction::class)->handle($request, $owner);

    $lockIndex = null;
    $firstWriteIndex = null;

    foreach ($queries as $index => $sql) {
        $lowered = mb_strtolower($sql);

        if (! str_contains($lowered, '"data_subject_requests"')) {
            continue;
        }

        // The lock is the only select keyed on the primary key; the
        // archive sweep selects on client_id, so match precisely or the
        // sweep gets mistaken for the lock and the assertion proves nothing.
        if ($lockIndex === null && str_starts_with($lowered, 'select') && str_contains($lowered, '"id" = ?')) {
            $lockIndex = $index;
        }

        if ($firstWriteIndex === null && str_starts_with($lowered, 'update')) {
            $firstWriteIndex = $index;
        }
    }

    expect($lockIndex)->not->toBeNull('The request row was never locked.')
        ->and($firstWriteIndex)->not->toBeNull('Nothing was written to data_subject_requests.')
        // Every write — the archive columns as well as the status — lands after
        // the lock, so all of it is inside the one transaction. Clearing archive
        // rows before the transaction, as this used to, put them before the lock.
        ->and($lockIndex)->toBeLessThan($firstWriteIndex);

    // SQLite compiles lockForUpdate() to nothing, so only the real driver can
    // confirm the SQL — see tests/Feature/Tenancy/LastOwnerMutationLockTest.php.
    if (DB::connection()->getDriverName() === 'pgsql') {
        expect(mb_strtolower($queries[$lockIndex]))->toContain('for update');
    }
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
