<?php

declare(strict_types=1);

use App\Tenancy\Enums\Role;
use App\Tenancy\Models\TenantMembership;
use App\Tenancy\Models\TenantRole;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
});

test('membership route binding 404s when the membership belongs to another tenant', function (): void {
    ['owner' => $ownerA, 'tenant' => $tenantA] = provisionWorkspace('Firm A');
    ['tenant' => $tenantB] = provisionWorkspace('Firm B');

    $foreignMember = workspaceMember($tenantB, Role::Adviser);
    $foreignMembership = TenantMembership::query()
        ->where('tenant_id', $tenantB->id)
        ->where('user_id', $foreignMember->id)
        ->firstOrFail();

    $this->actingAs($ownerA)
        ->patch(workspaceRoute('workspaces.members.update', $tenantA, [
            'membership' => $foreignMembership,
        ]), [
            'role' => Role::Reviewer->value,
        ])
        ->assertNotFound();
});

test('role route binding 404s when the role belongs to another tenant', function (): void {
    ['owner' => $ownerA, 'tenant' => $tenantA] = provisionWorkspace('Firm A');
    ['tenant' => $tenantB] = provisionWorkspace('Firm B');

    actingInWorkspace($tenantB);

    $foreignRole = TenantRole::query()->create([
        'name' => 'foreign-role',
        'guard_name' => 'web',
        'tenant_id' => $tenantB->id,
        'is_system' => false,
    ]);

    $this->actingAs($ownerA)
        ->patch(workspaceRoute('workspaces.roles.update', $tenantA, [
            'role' => $foreignRole,
        ]), [
            'name' => 'hijacked',
            'permissions' => [],
        ])
        ->assertNotFound();
});
