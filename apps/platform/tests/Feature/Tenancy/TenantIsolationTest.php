<?php

declare(strict_types=1);

use App\Accounts\Models\User;
use App\Clients\Models\Client;
use App\Dossiers\Enums\DossierPermission;
use App\Dossiers\Models\Dossier;
use App\Tenancy\Actions\ProvisionTenantAction;
use App\Tenancy\Enums\Role;
use App\Tenancy\Enums\TenantMembershipStatus;
use App\Tenancy\Http\Middleware\EnsureValidTenantMembership;
use App\Tenancy\Http\Middleware\InitializeTenantFromRoute;
use App\Tenancy\Http\Middleware\SetPermissionTeamContext;
use App\Tenancy\Models\TenantMembership;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Multitenancy\Http\Middleware\EnsureValidTenantSession;

pest()
    ->use(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
});

test('tenant member can view dossiers in their workspace', function (): void {
    $owner = User::factory()->create();
    $tenant = resolve(ProvisionTenantAction::class)->handle('Acme Accountants', $owner);

    $tenant->makeCurrent();
    setPermissionsTeamId($tenant->id);

    $client = Client::factory()->create(['tenant_id' => $tenant->id]);

    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
        'title' => 'Annual accounts 2025',
    ]);

    $this->actingAs($owner)
        ->withSession(['active_tenant_id' => $tenant->id])
        ->get(workspaceRoute('workspaces.dossiers.show', $tenant, ['dossier' => $dossier]))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('dossiers/show')
            ->where('dossier.title', 'Annual accounts 2025'));
});

test('users cannot access workspaces they do not belong to', function (): void {
    $owner = User::factory()->create();
    $outsider = User::factory()->create();
    $tenant = resolve(ProvisionTenantAction::class)->handle('Acme Accountants', $owner);

    $tenant->makeCurrent();
    setPermissionsTeamId($tenant->id);

    $client = Client::factory()->create(['tenant_id' => $tenant->id]);
    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
    ]);

    $this->actingAs($outsider)
        ->withSession(['active_tenant_id' => $tenant->id])
        ->get(workspaceRoute('workspaces.dossiers.show', $tenant, ['dossier' => $dossier]))
        ->assertNotFound();
});

test('users cannot read dossiers from another tenant via id guessing', function (): void {
    $ownerA = User::factory()->create();
    $ownerB = User::factory()->create();

    $tenantA = resolve(ProvisionTenantAction::class)->handle('Tenant A', $ownerA);
    $tenantB = resolve(ProvisionTenantAction::class)->handle('Tenant B', $ownerB);

    $tenantB->makeCurrent();
    setPermissionsTeamId($tenantB->id);
    $clientB = Client::factory()->create(['tenant_id' => $tenantB->id]);
    $foreignDossier = Dossier::factory()->create([
        'tenant_id' => $tenantB->id,
        'client_id' => $clientB->id,
        'title' => 'Foreign dossier',
    ]);

    $this->actingAs($ownerA)
        ->withSession(['active_tenant_id' => $tenantA->id])
        ->get(workspaceRoute('workspaces.dossiers.show', $tenantA, ['dossier' => $foreignDossier]))
        ->assertNotFound();
});

test('the workspace in the url takes precedence over the session tenant', function (): void {
    $user = User::factory()->create();

    $tenantA = resolve(ProvisionTenantAction::class)->handle('Tenant A', $user, ownerRole: Role::Owner);
    $tenantB = resolve(ProvisionTenantAction::class)->handle('Tenant B', User::factory()->create(), ownerRole: Role::Owner);

    TenantMembership::query()->create([
        'tenant_id' => $tenantB->id,
        'user_id' => $user->id,
        'status' => TenantMembershipStatus::Active,
        'joined_at' => now(),
    ]);

    setPermissionsTeamId($tenantB->id);
    $user->unsetRelation('roles');
    $user->assignRole(Role::ReadOnly->value);

    $this->actingAs($user)
        ->withSession(['active_tenant_id' => $tenantA->id])
        ->get(workspaceRoute('workspaces.dossiers.index', $tenantB))
        ->assertOk()
        ->assertSessionHas('active_tenant_id', $tenantB->id)
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('current_firm.slug', $tenantB->slug));
});

test('members can switch their active firm', function (): void {
    $user = User::factory()->create();

    $tenantA = resolve(ProvisionTenantAction::class)->handle('Tenant A', $user, ownerRole: Role::Owner);
    $tenantB = resolve(ProvisionTenantAction::class)->handle('Tenant B', User::factory()->create(), ownerRole: Role::Owner);

    TenantMembership::query()->create([
        'tenant_id' => $tenantB->id,
        'user_id' => $user->id,
        'status' => TenantMembershipStatus::Active,
        'joined_at' => now(),
    ]);

    setPermissionsTeamId($tenantB->id);
    $user->unsetRelation('roles');
    $user->assignRole(Role::ReadOnly->value);

    $this->actingAs($user)
        ->withSession(['active_tenant_id' => $tenantA->id])
        ->post(route('firms.activate', $tenantB))
        ->assertRedirect(workspaceRoute('workspaces.dashboard', $tenantB));

    $this->get(workspaceRoute('workspaces.dashboard', $tenantB))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('workspaces/dashboard'));
});

test('users cannot activate a firm they do not belong to', function (): void {
    $owner = User::factory()->create();
    $tenant = resolve(ProvisionTenantAction::class)->handle('Tenant A', $owner);
    $foreignTenant = resolve(ProvisionTenantAction::class)->handle('Tenant B', User::factory()->create());

    $this->actingAs($owner)
        ->withSession(['active_tenant_id' => $tenant->id])
        ->post(route('firms.activate', $foreignTenant))
        ->assertForbidden();
});

test('read only members cannot create dossiers', function (): void {
    $owner = User::factory()->create();
    $reader = User::factory()->create();
    $tenant = resolve(ProvisionTenantAction::class)->handle('Acme Accountants', $owner);

    TenantMembership::query()->create([
        'tenant_id' => $tenant->id,
        'user_id' => $reader->id,
        'status' => TenantMembershipStatus::Active,
        'joined_at' => now(),
    ]);

    setPermissionsTeamId($tenant->id);
    $reader->unsetRelation('roles');
    $reader->assignRole(Role::ReadOnly->value);

    expect($reader->can(DossierPermission::Create->value))->toBeFalse();
});

test('tenant routes are registered with the expected middleware', function (): void {
    $route = Route::getRoutes()->getByName('workspaces.dossiers.index');

    expect($route)->not->toBeNull()
        ->and($route->middleware())
        ->toContain('auth')
        ->toContain(InitializeTenantFromRoute::class)
        ->toContain(EnsureValidTenantMembership::class)
        ->toContain(SetPermissionTeamContext::class)->not->toContain(EnsureValidTenantSession::class)
        ->and($route->uri())
        ->toBe('workspaces/{tenant}/dossiers');
});

test('workspace pages load tenant memberships only once', function (): void {
    $owner = User::factory()->create();
    $tenant = resolve(ProvisionTenantAction::class)->handle('Acme Accountants', $owner);

    DB::flushQueryLog();
    DB::enableQueryLog();

    $this->actingAs($owner)
        ->withSession(['active_tenant_id' => $tenant->id])
        ->get(workspaceRoute('workspaces.dossiers.index', $tenant))
        ->assertOk();

    // Count only the relation eager-load, not the route initializer's whereHas exists subquery.
    $membershipEagerLoads = collect(DB::getQueryLog())
        ->filter(fn (array $query): bool => str_contains($query['query'], 'from "tenant_memberships"')
            && str_contains($query['query'], '"user_id" in'));

    expect($membershipEagerLoads)->toHaveCount(1);
});
