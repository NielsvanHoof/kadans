<?php

declare(strict_types=1);

use App\Accounts\Models\User;
use App\Clients\Models\Client;
use App\Dossiers\Models\Dossier;
use App\Tenancy\Enums\Role;
use App\Tenancy\Enums\TenantMembershipStatus;
use App\Tenancy\Models\TenantMembership;

beforeEach(function (): void {
    // phpunit boots with BROADCAST_CONNECTION=null; channel auth needs a signing driver.
    // Switching the default broadcaster creates a fresh instance without routes/channels.php.
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'testing-key',
        'broadcasting.connections.reverb.secret' => 'testing-secret',
        'broadcasting.connections.reverb.app_id' => 'testing',
        'broadcasting.connections.reverb.options' => [
            'host' => 'localhost',
            'port' => 8080,
            'scheme' => 'http',
            'useTLS' => false,
        ],
    ]);

    require base_path('routes/channels.php');
});

function dossierIn(int $tenantId): Dossier
{
    return Dossier::factory()->create([
        'tenant_id' => $tenantId,
        'client_id' => Client::factory()->create(['tenant_id' => $tenantId])->id,
    ]);
}

test('tenant member can authorize the workspace private channel', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();

    $this->actingAs($owner)
        ->postJson('/broadcasting/auth', [
            'channel_name' => 'private-workspaces.'.$tenant->slug,
            'socket_id' => '1234.5678',
        ])
        ->assertOk()
        ->assertJsonStructure(['auth']);
});

test('non-member cannot authorize the workspace private channel', function (): void {
    $outsider = User::factory()->create();
    ['tenant' => $tenant] = provisionWorkspace();

    $this->actingAs($outsider)
        ->postJson('/broadcasting/auth', [
            'channel_name' => 'private-workspaces.'.$tenant->slug,
            'socket_id' => '1234.5678',
        ])
        ->assertForbidden();
});

test('suspended member cannot authorize the workspace private channel', function (): void {
    ['tenant' => $tenant] = provisionWorkspace();
    $member = workspaceMember($tenant, Role::ReadOnly);

    // workspaceMember creates an active membership; suspend for this denial path.
    TenantMembership::query()
        ->where('tenant_id', $tenant->id)
        ->where('user_id', $member->id)
        ->update(['status' => TenantMembershipStatus::Suspended]);

    $this->actingAs($member)
        ->postJson('/broadcasting/auth', [
            'channel_name' => 'private-workspaces.'.$tenant->slug,
            'socket_id' => '1234.5678',
        ])
        ->assertForbidden();
});

test('tenant member can authorize the firm-wide pipeline channel', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();

    $this->actingAs($owner)
        ->postJson('/broadcasting/auth', [
            'channel_name' => 'private-workspaces.'.$tenant->slug.'.pipelines',
            'socket_id' => '1234.5678',
        ])
        ->assertOk();
});

test('non-member cannot authorize the firm-wide pipeline channel', function (): void {
    $outsider = User::factory()->create();
    ['tenant' => $tenant] = provisionWorkspace();

    $this->actingAs($outsider)
        ->postJson('/broadcasting/auth', [
            'channel_name' => 'private-workspaces.'.$tenant->slug.'.pipelines',
            'socket_id' => '1234.5678',
        ])
        ->assertForbidden();
});

test('a member can authorize a dossier channel in their own firm', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();

    actingInWorkspace($tenant);
    $dossier = dossierIn($tenant->id);

    $this->actingAs($owner)
        ->postJson('/broadcasting/auth', [
            'channel_name' => 'private-workspaces.'.$tenant->slug.'.dossiers.'.$dossier->id,
            'socket_id' => '1234.5678',
        ])
        ->assertOk();
});

test('a member cannot authorize a dossier belonging to another firm', function (): void {
    ['owner' => $owner, 'tenant' => $ownFirm] = provisionWorkspace();
    ['tenant' => $otherFirm] = provisionWorkspace();

    actingInWorkspace($otherFirm);
    $otherDossier = dossierIn($otherFirm->id);

    // The dossier id is supplied by the subscriber, so membership alone is not
    // enough: without the ownership check a member of one firm could name
    // another firm's dossier and receive its pipeline traffic. The slug in the
    // channel name is their own, so only the dossier lookup catches this.
    $this->actingAs($owner)
        ->postJson('/broadcasting/auth', [
            'channel_name' => 'private-workspaces.'.$ownFirm->slug.'.dossiers.'.$otherDossier->id,
            'socket_id' => '1234.5678',
        ])
        ->assertForbidden();
});

test('a non-numeric dossier segment is refused rather than queried', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();

    $this->actingAs($owner)
        ->postJson('/broadcasting/auth', [
            'channel_name' => 'private-workspaces.'.$tenant->slug.'.dossiers.not-a-number',
            'socket_id' => '1234.5678',
        ])
        ->assertForbidden();
});
