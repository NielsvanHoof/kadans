<?php

declare(strict_types=1);

use App\Tenancy\Enums\Role;
use App\Tenancy\Models\TenantRole;
use App\Tenancy\Policies\TenantRolePolicy;

covers(TenantRolePolicy::class);

test('owners can manage custom roles', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();

    $customRole = TenantRole::query()->create([
        'name' => 'operations',
        'guard_name' => 'web',
        'tenant_id' => $tenant->id,
        'is_system' => false,
    ]);

    $policy = resolve(TenantRolePolicy::class);

    actingInWorkspace($tenant);

    expect($policy->viewAny($owner))->toBeTrue()
        ->and($policy->create($owner))->toBeTrue()
        ->and($policy->update($owner, $customRole))->toBeTrue()
        ->and($policy->delete($owner, $customRole))->toBeTrue();
});

test('administrators cannot manage roles', function (): void {
    ['tenant' => $tenant] = provisionWorkspace();
    $admin = workspaceMember($tenant, Role::Administrator);

    $customRole = TenantRole::query()->create([
        'name' => 'operations',
        'guard_name' => 'web',
        'tenant_id' => $tenant->id,
        'is_system' => false,
    ]);

    $policy = resolve(TenantRolePolicy::class);

    actingInWorkspace($tenant);

    expect($policy->viewAny($admin))->toBeTrue()
        ->and($policy->create($admin))->toBeFalse()
        ->and($policy->update($admin, $customRole))->toBeFalse()
        ->and($policy->delete($admin, $customRole))->toBeFalse();
});

test('system roles cannot be updated or deleted even by owners', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();

    $systemRole = TenantRole::query()
        ->where('tenant_id', $tenant->id)
        ->where('name', Role::Administrator->value)
        ->firstOrFail();

    $policy = resolve(TenantRolePolicy::class);

    actingInWorkspace($tenant);

    expect($policy->update($owner, $systemRole))->toBeFalse()
        ->and($policy->delete($owner, $systemRole))->toBeFalse();
});

test('read-only users cannot view or manage roles', function (): void {
    ['tenant' => $tenant] = provisionWorkspace();
    $reader = workspaceMember($tenant, Role::ReadOnly);

    $customRole = TenantRole::query()->create([
        'name' => 'operations',
        'guard_name' => 'web',
        'tenant_id' => $tenant->id,
        'is_system' => false,
    ]);

    $policy = resolve(TenantRolePolicy::class);

    actingInWorkspace($tenant);

    expect($policy->viewAny($reader))->toBeFalse()
        ->and($policy->create($reader))->toBeFalse()
        ->and($policy->update($reader, $customRole))->toBeFalse()
        ->and($policy->delete($reader, $customRole))->toBeFalse();
});

test('owners cannot manage custom roles that belong to another tenant', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();
    ['tenant' => $otherTenant] = provisionWorkspace('Other firm');

    $foreignRole = TenantRole::query()->create([
        'name' => 'operations',
        'guard_name' => 'web',
        'tenant_id' => $otherTenant->id,
        'is_system' => false,
    ]);

    $policy = resolve(TenantRolePolicy::class);

    // Stay in the owner's firm so ManageRoles is present — denial must be cross-tenant.
    actingInWorkspace($tenant);

    expect($policy->update($owner, $foreignRole))->toBeFalse()
        ->and($policy->delete($owner, $foreignRole))->toBeFalse();
});
