<?php

declare(strict_types=1);

use App\Accounts\Models\User;
use App\Clients\Models\Client;
use App\Dossiers\Enums\DossierPermission;
use App\Dossiers\Models\Dossier;
use App\Tenancy\Enums\Role;
use App\Tenancy\Http\Middleware\EnforceStrictTenantScope;
use App\Tenancy\Http\Middleware\EnsureValidTenantMembership;
use App\Tenancy\Http\Middleware\InitializeTenantFromRoute;
use App\Tenancy\Http\Middleware\SetPermissionTeamContext;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Multitenancy\Http\Middleware\EnsureValidTenantSession;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
});

test('tenant member can view dossiers in their workspace', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace('Acme Accountants');

    $client = Client::factory()->create(['tenant_id' => $tenant->id]);

    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
        'title' => 'Annual accounts 2025',
    ]);

    $this->actingAs($owner)
        ->get(workspaceRoute('workspaces.dossiers.show', $tenant, ['dossier' => $dossier]))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('dossiers/show')
            ->where('dossier.title', 'Annual accounts 2025'));
});

test('users cannot access workspaces they do not belong to', function (): void {
    $outsider = User::factory()->create();
    ['tenant' => $tenant] = provisionWorkspace('Acme Accountants');

    $client = Client::factory()->create(['tenant_id' => $tenant->id]);
    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
    ]);

    $this->actingAs($outsider)
        ->get(workspaceRoute('workspaces.dossiers.show', $tenant, ['dossier' => $dossier]))
        ->assertNotFound();
});

test('users cannot read dossiers from another tenant via id guessing', function (): void {
    ['owner' => $ownerA, 'tenant' => $tenantA] = provisionWorkspace('Tenant A');
    ['tenant' => $tenantB] = provisionWorkspace('Tenant B');

    $clientB = Client::factory()->create(['tenant_id' => $tenantB->id]);
    $foreignDossier = Dossier::factory()->create([
        'tenant_id' => $tenantB->id,
        'client_id' => $clientB->id,
        'title' => 'Foreign dossier',
    ]);

    $this->actingAs($ownerA)
        ->get(workspaceRoute('workspaces.dossiers.show', $tenantA, ['dossier' => $foreignDossier]))
        ->assertNotFound();
});

test('the workspace host takes precedence over the session tenant', function (): void {
    $user = User::factory()->create();

    ['tenant' => $tenantA] = provisionWorkspace('Tenant A', $user);
    ['tenant' => $tenantB] = provisionWorkspace('Tenant B');

    $user = workspaceMember($tenantB, Role::ReadOnly, $user);

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

    ['tenant' => $tenantA] = provisionWorkspace('Tenant A', $user);
    ['tenant' => $tenantB] = provisionWorkspace('Tenant B');

    $user = workspaceMember($tenantB, Role::ReadOnly, $user);

    // firms.activate resolves the "current" tenant from the session
    // (InitializeTenantFromSession), so make tenant A active first to prove
    // the switch overrides it, rather than relying on membership fallback.
    actingInWorkspace($tenantA);

    $this->actingAs($user)
        ->post(route('firms.activate', $tenantB))
        ->assertRedirect(workspaceRoute('workspaces.dashboard', $tenantB));

    $this->get(workspaceRoute('workspaces.dashboard', $tenantB))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('workspaces/dashboard'));
});

test('users cannot activate a firm they do not belong to', function (): void {
    ['owner' => $owner] = provisionWorkspace('Tenant A');
    ['tenant' => $foreignTenant] = provisionWorkspace('Tenant B');

    $this->actingAs($owner)
        ->post(route('firms.activate', $foreignTenant))
        ->assertForbidden();
});

test('read only members cannot create dossiers', function (): void {
    ['tenant' => $tenant] = provisionWorkspace('Acme Accountants');
    $reader = workspaceMember($tenant, Role::ReadOnly);

    expect($reader->can(DossierPermission::Create->value))->toBeFalse();
});

test('tenant routes are registered with the expected middleware', function (): void {
    $route = Route::getRoutes()->getByName('workspaces.dossiers.index');

    expect($route)->not->toBeNull()
        ->and($route->middleware())
        ->toContain('auth')
        ->toContain(InitializeTenantFromRoute::class)
        ->toContain(EnforceStrictTenantScope::class)
        ->toContain(EnsureValidTenantMembership::class)
        ->toContain(SetPermissionTeamContext::class)->not->toContain(EnsureValidTenantSession::class)
        ->and($route->uri())
        ->toBe('dossiers')
        ->and($route->getDomain())
        ->toBe('{tenant}.'.config('tenancy-hosts.base_domain'));
});

test('workspace pages load tenant memberships only once', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace('Acme Accountants');

    DB::flushQueryLog();
    DB::enableQueryLog();

    $this->actingAs($owner)
        ->get(workspaceRoute('workspaces.dossiers.index', $tenant))
        ->assertOk();

    // Count only the relation eager-load, not the route initializer's whereHas exists subquery.
    $membershipEagerLoads = collect(DB::getQueryLog())
        ->filter(fn (array $query): bool => str_contains($query['query'], 'from "tenant_memberships"')
            && str_contains($query['query'], '"user_id" in'));

    expect($membershipEagerLoads)->toHaveCount(1);
});
