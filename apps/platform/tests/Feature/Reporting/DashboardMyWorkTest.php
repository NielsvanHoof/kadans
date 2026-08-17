<?php

declare(strict_types=1);

use App\Clients\Models\Client;
use App\Dossiers\Enums\DocumentRequestStatus;
use App\Dossiers\Enums\DossierStatus;
use App\Dossiers\Models\DocumentRequest;
use App\Dossiers\Models\Dossier;
use App\Reporting\Queries\FetchDashboardMyWorkQuery;
use App\Tenancy\Enums\Role;
use Database\Seeders\RolesAndPermissionsSeeder;
use Inertia\Testing\AssertableInertia as Assert;

covers(FetchDashboardMyWorkQuery::class);

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
});

/**
 * @param  array<string, mixed>  $attributes
 */
function myWorkDossier(object $tenant, object $client, array $attributes = []): Dossier
{
    return Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
        ...$attributes,
    ]);
}

/*
|--------------------------------------------------------------------------
| Per-user isolation
|--------------------------------------------------------------------------
|
| The reason my-work data is not stored in WorkspaceDashboardCache: that key is
| dashboard:metrics:{version}:{tenantId}:{date} with no user segment, so the
| first member to load the dashboard would populate it and every other member of
| the same firm would read that person's queue until the TTL expired.
|
| These two tests fail loudly if anyone moves these counters into the cache.
|
*/

test('two members of one firm each see only their own assigned work', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();
    $colleague = workspaceMember($tenant, Role::Adviser);
    $client = Client::factory()->create(['tenant_id' => $tenant->id]);

    myWorkDossier($tenant, $client, ['responsible_user_id' => $owner->id]);
    myWorkDossier($tenant, $client, ['responsible_user_id' => $owner->id]);
    myWorkDossier($tenant, $client, ['responsible_user_id' => $colleague->id]);
    myWorkDossier($tenant, $client, ['responsible_user_id' => null]);

    actingInWorkspace($tenant);
    $query = resolve(FetchDashboardMyWorkQuery::class);

    expect($query->handle($tenant, $owner)->assignedOpen)->toBe(2)
        ->and($query->handle($tenant, $colleague)->assignedOpen)->toBe(1);
});

test('a second member loading the dashboard is not served the first members numbers', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();
    $colleague = workspaceMember($tenant, Role::Adviser);
    $client = Client::factory()->create(['tenant_id' => $tenant->id]);

    myWorkDossier($tenant, $client, ['responsible_user_id' => $owner->id]);
    myWorkDossier($tenant, $client, ['responsible_user_id' => $owner->id]);
    myWorkDossier($tenant, $client, ['responsible_user_id' => $colleague->id]);

    actingInWorkspace($tenant);
    $url = workspaceRoute('workspaces.dashboard', $tenant);

    // A resolved deferred prop comes back as JSON, so these assert on the body
    // rather than view data — assertInertia only reads a full page response.
    $this->actingAs($owner)
        ->withHeaders(deferredPropHeaders('my_work'))
        ->get($url)
        ->assertOk()
        ->assertJsonPath('props.my_work.assigned_open', 2);

    $this->actingAs($colleague)
        ->withHeaders(deferredPropHeaders('my_work'))
        ->get($url)
        ->assertOk()
        ->assertJsonPath('props.my_work.assigned_open', 1);
});

test('my work is withheld from the first paint and offered as a deferred prop', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();

    actingInWorkspace($tenant);

    $this->actingAs($owner)
        ->get(workspaceRoute('workspaces.dashboard', $tenant))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('workspaces/dashboard')
            // Absent on first paint, then fetched by the follow-up request the
            // client makes — which is what loadDeferredProps replays.
            ->missing('my_work')
            ->loadDeferredProps('personal', fn (Assert $deferred): Assert => $deferred
                ->has('my_work')));
});

/*
|--------------------------------------------------------------------------
| Scoping and counters
|--------------------------------------------------------------------------
*/

test('completed dossiers drop out of the personal queue', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();
    $client = Client::factory()->create(['tenant_id' => $tenant->id]);

    myWorkDossier($tenant, $client, [
        'responsible_user_id' => $owner->id,
        'status' => DossierStatus::InReview,
    ]);
    myWorkDossier($tenant, $client, [
        'responsible_user_id' => $owner->id,
        'status' => DossierStatus::Completed,
    ]);

    actingInWorkspace($tenant);

    expect(resolve(FetchDashboardMyWorkQuery::class)->handle($tenant, $owner)->assignedOpen)->toBe(1);
});

test('another firms dossiers never reach the personal queue', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();

    ['tenant' => $foreignTenant] = provisionWorkspace('Other firm');
    actingInWorkspace($foreignTenant);
    $foreignClient = Client::factory()->create(['tenant_id' => $foreignTenant->id]);
    // Same responsible user, different firm.
    myWorkDossier($foreignTenant, $foreignClient, ['responsible_user_id' => $owner->id]);

    actingInWorkspace($tenant);

    expect(resolve(FetchDashboardMyWorkQuery::class)->handle($tenant, $owner)->assignedOpen)->toBe(0);
});

test('due date counters split overdue from the coming week', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();
    $client = Client::factory()->create(['tenant_id' => $tenant->id]);

    myWorkDossier($tenant, $client, [
        'responsible_user_id' => $owner->id,
        'due_date' => today()->subDays(3),
    ]);
    myWorkDossier($tenant, $client, [
        'responsible_user_id' => $owner->id,
        'due_date' => today()->addDays(2),
    ]);
    // Beyond the seven-day horizon.
    myWorkDossier($tenant, $client, [
        'responsible_user_id' => $owner->id,
        'due_date' => today()->addDays(30),
    ]);
    myWorkDossier($tenant, $client, [
        'responsible_user_id' => $owner->id,
        'due_date' => null,
    ]);

    actingInWorkspace($tenant);
    $myWork = resolve(FetchDashboardMyWorkQuery::class)->handle($tenant, $owner);

    expect($myWork->assignedOpen)->toBe(4)
        ->and($myWork->overdue)->toBe(1)
        ->and($myWork->dueThisWeek)->toBe(1);
});

test('agenda is ordered by urgency and signs days remaining', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();
    $client = Client::factory()->create(['tenant_id' => $tenant->id]);

    myWorkDossier($tenant, $client, [
        'responsible_user_id' => $owner->id,
        'title' => 'Due in three days',
        'due_date' => today()->addDays(3),
    ]);
    myWorkDossier($tenant, $client, [
        'responsible_user_id' => $owner->id,
        'title' => 'Late already',
        'due_date' => today()->subDays(2),
    ]);

    actingInWorkspace($tenant);
    $agenda = resolve(FetchDashboardMyWorkQuery::class)->handle($tenant, $owner)->agenda;

    expect($agenda)->toHaveCount(2)
        ->and($agenda[0]->title)->toBe('Late already')
        // Negative once the date has passed, so the client needs no date maths.
        ->and($agenda[0]->daysRemaining)->toBe(-2)
        ->and($agenda[1]->title)->toBe('Due in three days')
        ->and($agenda[1]->daysRemaining)->toBe(3);
});

test('submitted work on an assigned dossier counts as awaiting my review', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();
    $client = Client::factory()->create(['tenant_id' => $tenant->id]);

    $mine = myWorkDossier($tenant, $client, ['responsible_user_id' => $owner->id]);
    $theirs = myWorkDossier($tenant, $client, ['responsible_user_id' => null]);

    DocumentRequest::factory()->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $mine->id,
        'status' => DocumentRequestStatus::Submitted,
    ]);
    DocumentRequest::factory()->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $mine->id,
        'status' => DocumentRequestStatus::Pending,
    ]);
    DocumentRequest::factory()->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $theirs->id,
        'status' => DocumentRequestStatus::Submitted,
    ]);

    actingInWorkspace($tenant);

    // Counts dossiers needing review, not individual requests.
    expect(resolve(FetchDashboardMyWorkQuery::class)->handle($tenant, $owner)->awaitingMyReview)->toBe(1);
});
