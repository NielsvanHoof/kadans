<?php

declare(strict_types=1);

use App\Accounts\Models\User;
use App\Clients\Models\Client;
use App\Dossiers\Enums\DocumentRequestStatus;
use App\Dossiers\Enums\DossierStatus;
use App\Dossiers\Models\DocumentRequest;
use App\Dossiers\Models\Dossier;
use App\Tenancy\Actions\ProvisionTenantAction;
use App\Tenancy\Enums\Role;
use App\Tenancy\Enums\TenantMembershipStatus;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Models\TenantMembership;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

test('guests are redirected to the login page', function (): void {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('users without firm memberships are sent to firm setup', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('onboarding.firm.create'));
});

test('users without firm memberships can view firm setup', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('onboarding.firm.create'))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('onboarding/firm'));
});

test('users with one firm see its dashboard', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    $user = User::factory()->create();
    $tenant = resolve(ProvisionTenantAction::class)->handle('Acme Accountants', $user);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertRedirect(workspaceRoute('workspaces.dashboard', $tenant));

    $this->get(workspaceRoute('workspaces.dashboard', $tenant))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('workspaces/dashboard'));
});

test('firm dashboard shows tenant-scoped operational metrics', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    $owner = User::factory()->create();
    $tenant = resolve(ProvisionTenantAction::class)->handle('Acme Accountants', $owner);
    $tenant->makeCurrent();

    $client = Client::factory()->create(['tenant_id' => $tenant->id]);
    $awaitingClient = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
        'status' => DossierStatus::AwaitingClient,
    ]);
    $inReview = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
        'status' => DossierStatus::InReview,
    ]);
    Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
        'status' => DossierStatus::Completed,
    ]);
    DocumentRequest::factory()->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $awaitingClient->id,
        'status' => DocumentRequestStatus::Pending,
    ]);
    DocumentRequest::factory()->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $inReview->id,
        'status' => DocumentRequestStatus::Rejected,
    ]);
    DocumentRequest::factory()->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $inReview->id,
        'status' => DocumentRequestStatus::Submitted,
    ]);

    $foreignTenant = Tenant::factory()->create();
    $foreignTenant->makeCurrent();
    $foreignClient = Client::factory()->create(['tenant_id' => $foreignTenant->id]);
    $foreignDossier = Dossier::factory()->create([
        'tenant_id' => $foreignTenant->id,
        'client_id' => $foreignClient->id,
        'status' => DossierStatus::AwaitingClient,
    ]);
    DocumentRequest::factory()->create([
        'tenant_id' => $foreignTenant->id,
        'dossier_id' => $foreignDossier->id,
        'status' => DocumentRequestStatus::Rejected,
    ]);

    $tenant->makeCurrent();

    $this->actingAs($owner)
        ->withSession(['active_tenant_id' => $tenant->id])
        ->get(workspaceRoute('workspaces.dashboard', $tenant))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('workspaces/dashboard')
            ->where('metrics.clients', 1)
            ->where('metrics.open_dossiers', 2)
            ->where('metrics.needs_review', 1)
            ->where('metrics.awaiting_client', 2)
            ->where('metrics.overdue', 0)
            ->where('metrics.in_review', 1)
            ->where('metrics.submitted_document_requests', 1)
            ->where('metrics.outstanding_document_requests', 2)
            // Weekly trend fields power the dashboard spine comparison bars.
            ->has('metrics.open_dossiers_this_week')
            ->has('metrics.open_dossiers_last_week')
            ->has('metrics.needs_review_this_week')
            ->has('metrics.needs_review_last_week')
            ->has('metrics.awaiting_client_this_week')
            ->has('metrics.awaiting_client_last_week'));
});

test('dashboard metrics cache is used across requests', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    $owner = User::factory()->create();
    $tenant = resolve(ProvisionTenantAction::class)->handle('Acme Accountants', $owner);
    $tenant->makeCurrent();

    Client::factory()->create(['tenant_id' => $tenant->id]);

    // Warm the cache with clients = 1.
    $this->actingAs($owner)
        ->withSession(['active_tenant_id' => $tenant->id])
        ->get(workspaceRoute('workspaces.dashboard', $tenant))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('workspaces/dashboard')
            ->where('metrics.clients', 1));

    // Insert without model events so the invalidation trait does not fire.
    DB::table('clients')->insert([
        'tenant_id' => $tenant->id,
        'name' => 'Silent Client',
        'email' => 'silent@example.com',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Cached metrics should still report the pre-insert count.
    $this->get(workspaceRoute('workspaces.dashboard', $tenant))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('workspaces/dashboard')
            ->where('metrics.clients', 1));
});

test('dashboard metrics cache is forgotten when a dossier is created', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    $owner = User::factory()->create();
    $tenant = resolve(ProvisionTenantAction::class)->handle('Acme Accountants', $owner);
    $tenant->makeCurrent();

    $client = Client::factory()->create(['tenant_id' => $tenant->id]);

    $this->actingAs($owner)
        ->withSession(['active_tenant_id' => $tenant->id])
        ->get(workspaceRoute('workspaces.dashboard', $tenant))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('workspaces/dashboard')
            ->where('metrics.open_dossiers', 0));

    // Factory create fires the saved event, which forgets the cache.
    Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
        'status' => DossierStatus::AwaitingClient,
    ]);

    $this->get(workspaceRoute('workspaces.dashboard', $tenant))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('workspaces/dashboard')
            ->where('metrics.open_dossiers', 1));
});

test('dashboard metrics cache keys are tenant-scoped', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    $ownerA = User::factory()->create();
    $tenantA = resolve(ProvisionTenantAction::class)->handle('Acme Accountants', $ownerA);
    $tenantA->makeCurrent();
    Client::factory()->create(['tenant_id' => $tenantA->id]);

    $ownerB = User::factory()->create();
    $tenantB = resolve(ProvisionTenantAction::class)->handle('Beta Tax', $ownerB);
    $tenantB->makeCurrent();
    Client::factory()->count(2)->create(['tenant_id' => $tenantB->id]);

    // Warm tenant A's cache first.
    $tenantA->makeCurrent();
    $this->actingAs($ownerA)
        ->withSession(['active_tenant_id' => $tenantA->id])
        ->get(workspaceRoute('workspaces.dashboard', $tenantA))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('workspaces/dashboard')
            ->where('metrics.clients', 1));

    // Tenant B must see its own counts, not A's cached value.
    $tenantB->makeCurrent();
    $this->actingAs($ownerB)
        ->withSession(['active_tenant_id' => $tenantB->id])
        ->get(workspaceRoute('workspaces.dashboard', $tenantB))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('workspaces/dashboard')
            ->where('metrics.clients', 2));
});

test('users with multiple firms can choose which firm to open', function (): void {
    $user = User::factory()->create();
    $betaTenant = Tenant::factory()->create(['name' => 'Beta Tax', 'slug' => 'beta-tax']);
    $acmeTenant = Tenant::factory()->create(['name' => 'Acme Accountants', 'slug' => 'acme-accountants']);
    $inactiveTenant = Tenant::factory()->create(['name' => 'Dormant Firm', 'slug' => 'dormant-firm']);

    TenantMembership::factory()->create(['tenant_id' => $betaTenant->id, 'user_id' => $user->id]);
    TenantMembership::factory()->create(['tenant_id' => $acmeTenant->id, 'user_id' => $user->id]);
    TenantMembership::factory()->create([
        'tenant_id' => $inactiveTenant->id,
        'user_id' => $user->id,
        'status' => TenantMembershipStatus::Suspended,
    ]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('dashboard')
            ->where('firms', [
                ['name' => 'Acme Accountants', 'slug' => 'acme-accountants'],
                ['name' => 'Beta Tax', 'slug' => 'beta-tax'],
            ]));
});

test('verified users can set up their firm', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('onboarding.firm.store'), ['name' => 'Acme Accountants'])
        ->assertRedirect(workspaceRoute('workspaces.clients.create', 'acme-accountants'));

    $tenant = Tenant::query()->sole();
    $membership = TenantMembership::query()->sole();

    setPermissionsTeamId($tenant->id);
    $user->unsetRelation('roles');

    expect($tenant->slug)->toBe('acme-accountants')
        ->and($membership->tenant_id)->toBe($tenant->id)
        ->and($membership->user_id)->toBe($user->id)
        ->and($membership->isActive())->toBeTrue()
        ->and($user->hasRole(Role::Owner->value))->toBeTrue();
});

test('firm setup requires a name', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->from(route('onboarding.firm.create'))
        ->post(route('onboarding.firm.store'), ['name' => ''])
        ->assertRedirect(route('onboarding.firm.create'))
        ->assertSessionHasErrors('name');
});

test('unverified users are redirected to email verification', function (): void {
    $user = User::factory()->unverified()->create();
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));

    $response->assertRedirect(route('verification.notice'));

    $this->get(route('onboarding.firm.create'))
        ->assertRedirect(route('verification.notice'));
});

test('guests cannot set up a firm', function (): void {
    $this->post(route('onboarding.firm.store'), ['name' => 'Acme Accountants'])
        ->assertRedirect(route('login'));
});
