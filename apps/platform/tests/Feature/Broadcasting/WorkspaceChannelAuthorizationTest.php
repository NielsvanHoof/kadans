<?php

declare(strict_types=1);

use App\Accounts\Models\User;
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
