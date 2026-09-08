<?php

declare(strict_types=1);

use App\Tenancy\Enums\Role;
use App\Tenancy\Enums\WorkspacePermission;
use App\Tenancy\Models\TenantMembership;
use App\Tenancy\Models\TenantRole;
use App\Tenancy\Support\Members\WorkspaceMemberRoles;
use App\Tenancy\Support\Roles\PermissionRegistry;

test('owner-only permissions cannot be included in a firm-authored role', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();

    $this->actingAs($owner)
        ->post(workspaceRoute('workspaces.roles.store', $tenant), [
            'name' => 'shadow-owner',
            'permissions' => [
                WorkspacePermission::ViewMembers->value,
                WorkspacePermission::ManageRoles->value,
            ],
        ])
        ->assertSessionHasErrors('permissions.1');

    expect(TenantRole::query()
        ->where('tenant_id', $tenant->id)
        ->where('name', 'shadow-owner')
        ->exists())->toBeFalse();
});

/**
 * Firm settings became administrator-level, so an owner may now delegate it to
 * a firm-authored role. That is delegation, not escalation: the assignable
 * allowlist is still intersected with what the acting user already holds.
 */
test('an owner may delegate firm settings to a firm-authored role', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();

    $this->actingAs($owner)
        ->post(workspaceRoute('workspaces.roles.store', $tenant), [
            'name' => 'settings-editor',
            'permissions' => [
                WorkspacePermission::ManageSettings->value,
            ],
        ])
        ->assertSessionHasNoErrors();

    expect(TenantRole::query()
        ->where('tenant_id', $tenant->id)
        ->where('name', 'settings-editor')
        ->exists())->toBeTrue();
});

test('roles.manage still cannot be included in a firm-authored role', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();

    $this->actingAs($owner)
        ->post(workspaceRoute('workspaces.roles.store', $tenant), [
            'name' => 'role-editor',
            'permissions' => [
                WorkspacePermission::ManageRoles->value,
            ],
        ])
        ->assertSessionHasErrors('permissions.0');
});

test('an administrator cannot patch their own membership onto a role carrying roles.manage', function (): void {
    ['tenant' => $tenant] = provisionWorkspace();
    $admin = workspaceMember($tenant, Role::Administrator);

    // Bypass form validation to plant the escalation role the way an
    // earlier hole would have — the membership self-check must still hold.
    $escalationRole = TenantRole::query()->create([
        'name' => 'escalated-admin',
        'guard_name' => 'web',
        'tenant_id' => $tenant->id,
        'is_system' => false,
    ]);
    $escalationRole->givePermissionTo(WorkspacePermission::ManageRoles->value);

    $membership = TenantMembership::query()
        ->where('tenant_id', $tenant->id)
        ->where('user_id', $admin->id)
        ->firstOrFail();

    $this->actingAs($admin)
        ->patch(workspaceRoute('workspaces.members.update', $tenant, [
            'membership' => $membership,
        ]), [
            'role' => $escalationRole->name,
        ])
        ->assertForbidden();

    expect(resolve(WorkspaceMemberRoles::class)
        ->roleFor($admin->fresh(), $tenant))
        ->toBe(Role::Administrator->value);
});

test('members cannot change their own role assignment', function (): void {
    ['tenant' => $tenant] = provisionWorkspace();
    $admin = workspaceMember($tenant, Role::Administrator);

    $membership = TenantMembership::query()
        ->where('tenant_id', $tenant->id)
        ->where('user_id', $admin->id)
        ->firstOrFail();

    $this->actingAs($admin)
        ->patch(workspaceRoute('workspaces.members.update', $tenant, [
            'membership' => $membership,
        ]), [
            'role' => Role::Reviewer->value,
        ])
        ->assertForbidden();
});

test('firm assignable values exclude owner-only permissions', function (): void {
    $registry = resolve(PermissionRegistry::class);

    expect($registry->isOwnerOnly(WorkspacePermission::ManageRoles))->toBeTrue()
        ->and($registry->isOwnerOnly(WorkspacePermission::ManageSettings))->toBeFalse()
        ->and($registry->isOwnerOnly(WorkspacePermission::ViewMembers))->toBeFalse()
        ->and($registry->firmAssignableValues())
        ->not->toContain(WorkspacePermission::ManageRoles->value)
        ->toContain(WorkspacePermission::ManageSettings->value)
        ->toContain(WorkspacePermission::ViewMembers->value);
});
