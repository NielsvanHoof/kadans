<?php

declare(strict_types=1);

use App\Actions\Tenancy\ProvisionTenantAction;
use App\Enums\AuditEvent;
use App\Enums\Permission;
use App\Models\Activity;
use App\Models\Client;
use App\Models\Dossier;
use App\Models\TenantMembership;
use App\Models\User;
use App\Support\Platform\SupportSession;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

test('non-support users cannot open the platform support page', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('platform.support.index'))
        ->assertForbidden();
});

test('support users without a membership cannot open a firm until a session starts', function () {
    $owner = User::factory()->create();
    $tenant = app(ProvisionTenantAction::class)->handle('Acme Accountants', $owner);
    $support = User::factory()->platformSupport()->create();

    $this->actingAs($support)
        ->get(workspaceRoute('workspaces.dashboard', $tenant))
        ->assertNotFound();
});

test('support users can start a read-only session and view dossiers', function () {
    $owner = User::factory()->create();
    $tenant = app(ProvisionTenantAction::class)->handle('Acme Accountants', $owner);
    $support = User::factory()->platformSupport()->create();

    $tenant->makeCurrent();
    $client = Client::factory()->create(['tenant_id' => $tenant->id]);
    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
        'title' => 'Support dossier',
    ]);

    $this->actingAs($support)
        ->post(route('platform.support.store', $tenant), [
            'reason' => 'Investigating ticket #42',
        ])
        ->assertRedirect(workspaceRoute('workspaces.dashboard', $tenant));

    expect(Activity::query()
        ->where('event', AuditEvent::SupportSessionStarted->value)
        ->where('causer_id', $support->id)
        ->exists())->toBeTrue();

    // Session from the store request carries into subsequent requests.
    $this->actingAs($support)
        ->get(workspaceRoute('workspaces.dashboard', $tenant))
        ->assertOk();

    $this->actingAs($support)
        ->get(workspaceRoute('workspaces.dossiers.index', $tenant))
        ->assertOk();

    $this->actingAs($support)
        ->get(workspaceRoute('workspaces.dossiers.show', $tenant, ['dossier' => $dossier]))
        ->assertOk();

    // Support users must not appear as firm members.
    expect(TenantMembership::query()
        ->where('tenant_id', $tenant->id)
        ->where('user_id', $support->id)
        ->exists())->toBeFalse();
});

test('support session is read-only and cannot manage members', function () {
    $owner = User::factory()->create();
    $tenant = app(ProvisionTenantAction::class)->handle('Acme Accountants', $owner);
    $support = User::factory()->platformSupport()->create();

    $this->actingAs($support)
        ->post(route('platform.support.store', $tenant), [
            'reason' => 'Bug reproduction',
        ])
        ->assertRedirect();

    $this->actingAs($support)
        ->get(workspaceRoute('workspaces.members.index', $tenant))
        ->assertForbidden();

    $this->actingAs($support)
        ->get(workspaceRoute('workspaces.dossiers.create', $tenant))
        ->assertForbidden();

    actingInWorkspace($tenant);
    expect($support->fresh()->can(Permission::ManageMembers->value))->toBeFalse()
        ->and($support->fresh()->can(Permission::CreateDossiers->value))->toBeFalse()
        ->and($support->fresh()->can(Permission::ViewDossiers->value))->toBeTrue()
        ->and($support->fresh()->can(Permission::ViewAuditLog->value))->toBeTrue();
});

test('expired support sessions are rejected', function () {
    $owner = User::factory()->create();
    $tenant = app(ProvisionTenantAction::class)->handle('Acme Accountants', $owner);
    $support = User::factory()->platformSupport()->create();

    $this->actingAs($support)
        ->post(route('platform.support.store', $tenant), [
            'reason' => 'Expired access check',
        ])
        ->assertRedirect();

    $payload = session(SupportSession::SESSION_KEY);
    $payload['expires_at'] = now()->subMinute()->toIso8601String();

    $this->withSession([
        'active_tenant_id' => $tenant->id,
        SupportSession::SESSION_KEY => $payload,
    ])
        ->get(workspaceRoute('workspaces.dashboard', $tenant))
        ->assertNotFound();
});

test('ending a support session removes firm access', function () {
    $owner = User::factory()->create();
    $tenant = app(ProvisionTenantAction::class)->handle('Acme Accountants', $owner);
    $support = User::factory()->platformSupport()->create();

    $this->actingAs($support)
        ->post(route('platform.support.store', $tenant), [
            'reason' => 'Temporary look',
        ])
        ->assertRedirect();

    $this->actingAs($support)
        ->delete(route('platform.support.destroy'))
        ->assertRedirect(route('platform.support.index'));

    expect(Activity::query()
        ->where('event', AuditEvent::SupportSessionEnded->value)
        ->where('causer_id', $support->id)
        ->exists())->toBeTrue();

    $this->actingAs($support)
        ->get(workspaceRoute('workspaces.dashboard', $tenant))
        ->assertNotFound();
});

test('support index lists firms for platform staff', function () {
    $owner = User::factory()->create();
    app(ProvisionTenantAction::class)->handle('Listed Firm', $owner);
    app(ProvisionTenantAction::class)->handle('Other Firm', $owner);
    $support = User::factory()->platformSupport()->create();

    $this->actingAs($support)
        ->get(route('platform.support.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('platform/support')
            ->has('tenants', 2)
            ->has('filters'));

    $this->actingAs($support)
        ->get(route('platform.support.index', ['filter' => ['q' => 'Listed']]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('platform/support')
            ->has('tenants', 1)
            ->where('tenants.0.name', 'Listed Firm')
            ->where('filters.q', 'Listed'));
});

test('support session grants download and audit view but denies client management', function () {
    $owner = User::factory()->create();
    $tenant = app(ProvisionTenantAction::class)->handle('Acme Accountants', $owner);
    $support = User::factory()->platformSupport()->create();

    $this->actingAs($support)
        ->post(route('platform.support.store', $tenant), [
            'reason' => 'Permission matrix check',
        ])
        ->assertRedirect();

    actingInWorkspace($tenant);
    $support = $support->fresh();

    expect($support->hasTenantPermission(Permission::ViewDossiers, $tenant))->toBeTrue()
        ->and($support->hasTenantPermission(Permission::DownloadDocuments, $tenant))->toBeTrue()
        ->and($support->hasTenantPermission(Permission::ViewAuditLog, $tenant))->toBeTrue()
        ->and($support->hasTenantPermission(Permission::ManageClients, $tenant))->toBeFalse()
        ->and($support->hasTenantPermission(Permission::CreateDossiers, $tenant))->toBeFalse()
        ->and($support->hasTenantPermission(Permission::ReviewDocuments, $tenant))->toBeFalse()
        ->and($support->hasTenantPermission(Permission::ManageMembers, $tenant))->toBeFalse()
        ->and($support->hasTenantPermission(Permission::ManageTemplates, $tenant))->toBeFalse();

    $this->actingAs($support)
        ->get(workspaceRoute('workspaces.activity.index', $tenant))
        ->assertOk();

    $this->actingAs($support)
        ->get(workspaceRoute('workspaces.clients.index', $tenant))
        ->assertForbidden();

    $this->actingAs($support)
        ->get(workspaceRoute('workspaces.media.index', $tenant))
        ->assertOk();
});

test('support session stays read-only even when the user is also a firm member', function () {
    $owner = User::factory()->create();
    $tenant = app(ProvisionTenantAction::class)->handle('Acme Accountants', $owner);

    // Engineer who is both platform support and a firm member (no Spatie role beyond membership).
    $support = User::factory()->platformSupport()->create();
    TenantMembership::query()->create([
        'tenant_id' => $tenant->id,
        'user_id' => $support->id,
        'status' => App\Enums\TenantMembershipStatus::Active,
        'joined_at' => now(),
    ]);

    $this->actingAs($support)
        ->post(route('platform.support.store', $tenant), [
            'reason' => 'Member who is also support',
        ])
        ->assertRedirect();

    $this->actingAs($support)
        ->get(workspaceRoute('workspaces.dashboard', $tenant))
        ->assertOk();

    $this->actingAs($support)
        ->get(workspaceRoute('workspaces.members.index', $tenant))
        ->assertForbidden();
});

test('support users are not listed as workspace members', function () {
    $owner = User::factory()->create();
    $tenant = app(ProvisionTenantAction::class)->handle('Acme Accountants', $owner);
    $support = User::factory()->platformSupport()->create();

    $this->actingAs($support)
        ->post(route('platform.support.store', $tenant), [
            'reason' => 'Member list check',
        ])
        ->assertRedirect();

    $this->actingAs($owner)
        ->withSession(['active_tenant_id' => $tenant->id])
        ->get(workspaceRoute('workspaces.members.index', $tenant))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('workspaces/members/index')
            ->has('members', 1)
            ->where('members.0.email', $owner->email));
});
