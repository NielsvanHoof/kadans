<?php

declare(strict_types=1);

use App\Audit\Enums\AuditEvent;
use App\Audit\Models\Activity;
use App\Clients\Models\Client;
use App\Platform\Actions\DataSubject\GenerateDataSubjectExportAction;
use App\Platform\Actions\DataSubject\LogDataSubjectRequestAction;
use App\Platform\Enums\DataSubjectPermission;
use App\Platform\Enums\DataSubjectRequestStatus;
use App\Platform\Enums\DataSubjectRequestType;
use App\Platform\Jobs\DataSubject\AnonymizeDataSubjectJob;
use App\Platform\Jobs\DataSubject\GenerateDataSubjectExportJob;
use App\Platform\Models\DataSubjectRequest;
use App\Tenancy\Enums\Role;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * These permissions are deliberately not in any role's defaults, so only owners
 * hold them until a firm grants them explicitly: an export puts every document
 * about one person in a single file, and erasure cannot be undone.
 */
function requestFixture(): array
{
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();

    $client = Client::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Marieke de Vries',
    ]);

    return ['owner' => $owner, 'tenant' => $tenant, 'client' => $client];
}

test('an owner can open the requests screen', function (): void {
    ['owner' => $owner, 'tenant' => $tenant, 'client' => $client] = requestFixture();

    resolve(LogDataSubjectRequestAction::class)
        ->handle($client, $owner, DataSubjectRequestType::Erasure);

    $this->actingAs($owner)
        ->get(workspaceRoute('workspaces.data-subject-requests.index', $tenant))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('workspaces/data-subject-requests/index')
            ->where('can_manage', true)
            ->has('requests.data', 1)
            ->has('clients', 1));
});

test('the requests screen still lists a request after its client is archived', function (): void {
    ['owner' => $owner, 'tenant' => $tenant, 'client' => $client] = requestFixture();

    resolve(LogDataSubjectRequestAction::class)
        ->handle($client, $owner, DataSubjectRequestType::Export);

    // Soft-delete blanks the default BelongsTo; the compliance list must keep
    // showing the person the request was about.
    $client->delete();

    $this->actingAs($owner)
        ->get(workspaceRoute('workspaces.data-subject-requests.index', $tenant))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('workspaces/data-subject-requests/index')
            ->has('requests.data', 1)
            ->where('requests.data.0.client_name', 'Marieke de Vries'));
});

test('roles without the permission cannot reach the screen', function (Role $role): void {
    ['tenant' => $tenant] = requestFixture();
    $member = workspaceMember($tenant, $role);

    $this->actingAs($member)
        ->get(workspaceRoute('workspaces.data-subject-requests.index', $tenant))
        ->assertForbidden();
})->with([
    'administrator' => [Role::Administrator],
    'adviser' => [Role::Adviser],
    'read-only' => [Role::ReadOnly],
]);

test('a role granted view may read but not log a request', function (): void {
    ['tenant' => $tenant, 'client' => $client] = requestFixture();

    $member = workspaceMember($tenant, Role::Administrator);
    $member->givePermissionTo(DataSubjectPermission::View->value);

    $this->actingAs($member)
        ->get(workspaceRoute('workspaces.data-subject-requests.index', $tenant))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('can_manage', false)
            // The picker is only for logging, so it is not sent.
            ->has('clients', 0));

    $this->actingAs($member)
        ->post(workspaceRoute('workspaces.data-subject-requests.store', $tenant), [
            'client_id' => $client->id,
            'type' => DataSubjectRequestType::Export->value,
        ])
        ->assertForbidden();
});

test('logging a request through the screen records it', function (): void {
    ['owner' => $owner, 'tenant' => $tenant, 'client' => $client] = requestFixture();

    $this->actingAs($owner)
        ->post(workspaceRoute('workspaces.data-subject-requests.store', $tenant), [
            'client_id' => $client->id,
            'type' => DataSubjectRequestType::Export->value,
        ])
        ->assertRedirect(workspaceRoute('workspaces.data-subject-requests.index', $tenant));

    expect(DataSubjectRequest::query()->where('client_id', $client->id)->exists())->toBeTrue();
});

test('fulfilling an export queues the build rather than blocking the request', function (): void {
    Queue::fake();

    ['owner' => $owner, 'tenant' => $tenant, 'client' => $client] = requestFixture();

    $request = resolve(LogDataSubjectRequestAction::class)
        ->handle($client, $owner, DataSubjectRequestType::Export);

    $this->actingAs($owner)
        ->post(workspaceRoute('workspaces.data-subject-requests.fulfil', $tenant, [
            'dataSubjectRequest' => $request->id,
        ]))
        ->assertRedirect();

    // Not just "queued" — queued somewhere that cannot stall interactive work.
    // This build has a ten-minute timeout, and `default` is drained first and
    // carries every broadcast, queued notification and the health heartbeat.
    Queue::assertPushed(
        GenerateDataSubjectExportJob::class,
        fn (GenerateDataSubjectExportJob $job): bool => $job->queue === 'dossier-export',
    );
});

test('fulfilling an erasure queues anonymization rather than blocking the request', function (): void {
    Queue::fake();

    ['owner' => $owner, 'tenant' => $tenant, 'client' => $client] = requestFixture();

    $request = resolve(LogDataSubjectRequestAction::class)
        ->handle($client, $owner, DataSubjectRequestType::Erasure);

    $this->actingAs($owner)
        ->post(workspaceRoute('workspaces.data-subject-requests.fulfil', $tenant, [
            'dataSubjectRequest' => $request->id,
        ]))
        ->assertRedirect();

    Queue::assertPushed(
        AnonymizeDataSubjectJob::class,
        fn (AnonymizeDataSubjectJob $job): bool => $job->dataSubjectRequestId === $request->id
            && $job->fulfilledByUserId === $owner->id
            && $job->queue === 'dossier-export',
    );

    expect($client->refresh()->name)->toBe('Marieke de Vries');
});

test('a refusal requires a reason', function (): void {
    ['owner' => $owner, 'tenant' => $tenant, 'client' => $client] = requestFixture();

    $request = resolve(LogDataSubjectRequestAction::class)
        ->handle($client, $owner, DataSubjectRequestType::Erasure);

    $this->actingAs($owner)
        ->from(workspaceRoute('workspaces.data-subject-requests.index', $tenant))
        ->post(workspaceRoute('workspaces.data-subject-requests.refuse', $tenant, [
            'dataSubjectRequest' => $request->id,
        ]), ['refusal_reason' => 'no'])
        ->assertSessionHasErrors('refusal_reason');

    expect($request->refresh()->status)->toBe(DataSubjectRequestStatus::Pending);

    $this->actingAs($owner)
        ->post(workspaceRoute('workspaces.data-subject-requests.refuse', $tenant, [
            'dataSubjectRequest' => $request->id,
        ]), ['refusal_reason' => 'The dossiers fall inside the statutory retention period.'])
        ->assertRedirect();

    expect($request->refresh()->status)->toBe(DataSubjectRequestStatus::Refused);
});

/**
 * The archive is every document the firm holds about one person, so it sits
 * behind the same recent-password gate as every document route.
 */
test('downloading the archive requires a recent password confirmation', function (): void {
    Storage::fake('local');
    config(['exports.disk' => 'local']);

    ['owner' => $owner, 'tenant' => $tenant, 'client' => $client] = requestFixture();

    $request = resolve(LogDataSubjectRequestAction::class)
        ->handle($client, $owner, DataSubjectRequestType::Export);
    resolve(GenerateDataSubjectExportAction::class)->handle($request);

    $url = workspaceRoute('workspaces.data-subject-requests.download', $tenant, [
        'dataSubjectRequest' => $request->id,
    ]);

    $this->actingAs($owner)->get($url)->assertRedirect(route('password.confirm'));

    $this->actingAs($owner)
        ->withSession(['auth.password_confirmed_at' => Date::now()->timestamp])
        ->get($url)
        ->assertOk();

    expect(Activity::query()
        ->where('event', AuditEvent::DataSubjectExportDownloaded->value)
        ->where('subject_id', $request->id)
        ->exists())->toBeTrue();
});

test('an expired archive is no longer downloadable', function (): void {
    Storage::fake('local');
    config(['exports.disk' => 'local']);

    ['owner' => $owner, 'tenant' => $tenant, 'client' => $client] = requestFixture();

    $request = resolve(LogDataSubjectRequestAction::class)
        ->handle($client, $owner, DataSubjectRequestType::Export);
    resolve(GenerateDataSubjectExportAction::class)->handle($request);

    $this->travel((int) config('retention.data_subject_export_hours') + 1)->hours();

    $this->actingAs($owner)
        ->withSession(['auth.password_confirmed_at' => Date::now()->timestamp])
        ->get(workspaceRoute('workspaces.data-subject-requests.download', $tenant, [
            'dataSubjectRequest' => $request->id,
        ]))
        ->assertForbidden();
});

/**
 * Tenant scoping: a request belongs to one firm, and another firm must not be
 * able to reach it by guessing an id.
 */
test('a request from another firm is not reachable', function (): void {
    ['owner' => $firstOwner, 'client' => $firstClient] = requestFixture();

    $request = resolve(LogDataSubjectRequestAction::class)
        ->handle($firstClient, $firstOwner, DataSubjectRequestType::Erasure);

    ['owner' => $secondOwner, 'tenant' => $secondTenant] = requestFixture();

    $this->actingAs($secondOwner)
        ->post(workspaceRoute('workspaces.data-subject-requests.fulfil', $secondTenant, [
            'dataSubjectRequest' => $request->id,
        ]))
        ->assertNotFound();
});

/*
|--------------------------------------------------------------------------
| Bounded queries
|--------------------------------------------------------------------------
|
| The index used to load every request the tenant had ever had, with three
| eager loads, on each render. DataSubjectRequest is deliberately not
| Prunable — a compliance record has to outlive the request it describes —
| so the set only ever grows.
|
*/

test('the requests index is paginated rather than loading every request ever logged', function (): void {
    ['owner' => $owner, 'tenant' => $tenant, 'client' => $client] = requestFixture();

    DataSubjectRequest::factory()->count(40)->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
        'requested_by' => $owner->id,
    ]);

    $this->actingAs($owner)
        ->get(workspaceRoute('workspaces.data-subject-requests.index', $tenant))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('workspaces/data-subject-requests/index')
            ->has('requests.data', 15)
            ->where('requests.total', 40)
            ->where('requests.last_page', 3)
            // The toolbar config the shared index UI needs.
            ->has('indexQuery.filters')
            ->has('indexQuery.sorts')
            ->etc());
});

test('the requests index keeps open requests ahead of closed ones', function (): void {
    ['owner' => $owner, 'tenant' => $tenant, 'client' => $client] = requestFixture();

    // A closed request with the earliest deadline must still rank below an
    // open one: a closed request can no longer breach.
    DataSubjectRequest::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
        'requested_by' => $owner->id,
        'status' => DataSubjectRequestStatus::Completed,
        'due_at' => now()->subDays(10),
    ]);

    $open = DataSubjectRequest::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
        'requested_by' => $owner->id,
        'status' => DataSubjectRequestStatus::Pending,
        'due_at' => now()->addDays(5),
    ]);

    $this->actingAs($owner)
        ->get(workspaceRoute('workspaces.data-subject-requests.index', $tenant))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('workspaces/data-subject-requests/index')
            ->where('requests.data.0.id', $open->id)
            ->etc());
});

test('the client picker is bounded and ordered by the database', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = requestFixture();

    Client::factory()->count(3)->sequence(
        ['name' => 'Zeta Holding'],
        ['name' => 'Alpha BV'],
        ['name' => 'Midden NV'],
    )->create(['tenant_id' => $tenant->id]);

    $this->actingAs($owner)
        ->get(workspaceRoute('workspaces.data-subject-requests.index', $tenant))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('clients.0.name', 'Alpha BV')
            ->where('clients.1.name', 'Marieke de Vries')
            ->where('clients.2.name', 'Midden NV')
            ->where('clients.3.name', 'Zeta Holding')
            ->etc());
});
