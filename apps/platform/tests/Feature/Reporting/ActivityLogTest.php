<?php

declare(strict_types=1);

use App\Accounts\Models\User;
use App\Audit\Actions\LogAuditActivityAction;
use App\Audit\Enums\AuditEvent;
use App\Audit\Models\Activity;
use App\ClientPortal\Actions\CreateClientAccessGrantAction;
use App\Clients\Models\Client;
use App\Dossiers\Models\Dossier;
use App\Tenancy\Enums\Role;
use Database\Seeders\RolesAndPermissionsSeeder;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
});

test('owner can view the activity log with structured audit entries', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();

    $client = Client::factory()->create(['tenant_id' => $tenant->id]);
    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
        'title' => 'Payroll 2025',
    ]);

    // Clear dossier-created noise so the manual view event is easy to assert.
    Activity::query()->delete();

    resolve(LogAuditActivityAction::class)->handle(
        AuditEvent::DossierViewed,
        $dossier,
        causer: $owner,
        includeRequestContext: false,
    );

    $this->actingAs($owner)
        ->get(workspaceRoute('workspaces.activity.index', $tenant))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('workspaces/activity/index')
            ->has('activities.data', 1)
            ->where('activities.total', 1)
            ->has('indexQuery')
            ->where('activities.data.0.event', AuditEvent::DossierViewed->value)
            ->where('activities.data.0.label', AuditEvent::DossierViewed->label())
            ->where('activities.data.0.causer_name', $owner->name)
            ->where('activities.data.0.subject.type', 'Dossier')
            ->where('activities.data.0.subject.id', $dossier->id)
            ->where('activities.data.0.subject.name', 'Payroll 2025'));
});

test('activity log does not include entries from another tenant', function (): void {
    ['owner' => $ownerA, 'tenant' => $tenantA] = provisionWorkspace('Tenant A');
    ['owner' => $ownerB, 'tenant' => $tenantB] = provisionWorkspace('Tenant B');

    $clientB = Client::factory()->create(['tenant_id' => $tenantB->id]);
    $dossierB = Dossier::factory()->create([
        'tenant_id' => $tenantB->id,
        'client_id' => $clientB->id,
        'title' => 'Foreign dossier',
    ]);
    Activity::query()->delete();
    resolve(LogAuditActivityAction::class)->handle(
        AuditEvent::DossierViewed,
        $dossierB,
        causer: $ownerB,
        includeRequestContext: false,
    );

    actingInWorkspace($tenantA);
    $clientA = Client::factory()->create(['tenant_id' => $tenantA->id]);
    $dossierA = Dossier::factory()->create([
        'tenant_id' => $tenantA->id,
        'client_id' => $clientA->id,
        'title' => 'Own dossier',
    ]);
    Activity::query()->delete();
    resolve(LogAuditActivityAction::class)->handle(
        AuditEvent::DossierCompleted,
        $dossierA,
        causer: $ownerA,
        includeRequestContext: false,
    );

    $this->actingAs($ownerA)
        ->get(workspaceRoute('workspaces.activity.index', $tenantA))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('workspaces/activity/index')
            ->has('activities.data', 1)
            ->where('activities.data.0.event', AuditEvent::DossierCompleted->value)
            ->where('activities.data.0.subject.name', 'Own dossier'));
});

test('activity log resolves client access grant subjects using the dossier title', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();

    $client = Client::factory()->create(['tenant_id' => $tenant->id]);
    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
        'title' => 'Annual accounts 2025',
    ]);

    $result = resolve(CreateClientAccessGrantAction::class)->handle($dossier, $owner, 7);

    Activity::query()->delete();

    resolve(LogAuditActivityAction::class)->handle(
        AuditEvent::ClientPortalAccessGrantCreated,
        $result['grant'],
        causer: $owner,
        includeRequestContext: false,
    );

    $this->actingAs($owner)
        ->get(workspaceRoute('workspaces.activity.index', $tenant))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('workspaces/activity/index')
            ->has('activities.data', 1)
            ->where('activities.data.0.event', AuditEvent::ClientPortalAccessGrantCreated->value)
            ->where('activities.data.0.subject.type', 'ClientAccessGrant')
            ->where('activities.data.0.subject.id', $result['grant']->id)
            ->where('activities.data.0.subject.name', 'Annual accounts 2025'));
});

test('reviewer cannot view the activity log', function (): void {
    ['tenant' => $tenant] = provisionWorkspace();
    $reviewer = workspaceMember($tenant, Role::Reviewer);

    $this->actingAs($reviewer)
        ->withSession(['auth.password_confirmed_at' => now()->getTimestamp()])
        ->get(workspaceRoute('workspaces.activity.index', $tenant))
        ->assertForbidden();
});

test('guests cannot view the activity log', function (): void {
    $this->get(workspaceRoute('workspaces.activity.index', 'acme-accountants'))
        ->assertRedirect(route('login'));
});

test('activity log can be filtered by actor and date range', function (): void {
    $owner = User::factory()->create(['name' => 'Owner User']);
    $other = User::factory()->create(['name' => 'Other User']);
    ['tenant' => $tenant] = provisionWorkspace(owner: $owner);

    $client = Client::factory()->create(['tenant_id' => $tenant->id]);
    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
        'title' => 'Payroll 2025',
    ]);

    Activity::query()->delete();

    resolve(LogAuditActivityAction::class)->handle(
        AuditEvent::DossierViewed,
        $dossier,
        causer: $owner,
        includeRequestContext: false,
    );

    $this->travel(2)->days();

    resolve(LogAuditActivityAction::class)->handle(
        AuditEvent::DossierCompleted,
        $dossier,
        causer: $other,
        includeRequestContext: false,
    );

    $this->actingAs($owner)
        ->get(
            workspaceRoute('workspaces.activity.index', $tenant)
            .'?'.http_build_query(['filter' => ['causer_id' => (string) $owner->id]]),
        )
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('workspaces/activity/index')
            ->has('activities.data', 1)
            ->where('activities.data.0.event', AuditEvent::DossierViewed->value)
            ->where('activities.data.0.causer_name', 'Owner User'));

    $from = now()->subDay()->toDateString();
    $to = now()->addDay()->toDateString();

    $this->actingAs($owner)
        ->get(
            workspaceRoute('workspaces.activity.index', $tenant)
            .'?'.http_build_query([
                'filter' => [
                    'created_from' => $from,
                    'created_to' => $to,
                ],
            ]),
        )
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('workspaces/activity/index')
            ->has('activities.data', 1)
            ->where('activities.data.0.event', AuditEvent::DossierCompleted->value));
});
