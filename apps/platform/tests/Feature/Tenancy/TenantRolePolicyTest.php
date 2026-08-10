<?php

declare(strict_types=1);

use App\Tenancy\Enums\Role;
use App\Tenancy\Models\TenantRole;
use App\Tenancy\Policies\TenantRolePolicy;

covers(TenantRolePolicy::class);

test('role management abilities match the actor', function (string $actor, bool $canView, bool $canManage): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();

    $user = match ($actor) {
        'owner' => $owner,
        'administrator' => workspaceMember($tenant, Role::Administrator),
        'read only' => workspaceMember($tenant, Role::ReadOnly),
        default => throw new InvalidArgumentException("Unknown actor [{$actor}]."),
    };

    $customRole = TenantRole::query()->create([
        'name' => 'operations',
        'guard_name' => 'web',
        'tenant_id' => $tenant->id,
        'is_system' => false,
    ]);

    $policy = resolve(TenantRolePolicy::class);

    actingInWorkspace($tenant);

    expect($policy->viewAny($user))->toBe($canView)
        ->and($policy->create($user))->toBe($canManage)
        ->and($policy->update($user, $customRole))->toBe($canManage)
        ->and($policy->delete($user, $customRole))->toBe($canManage);
})->with([
    'owner' => ['owner', true, true],
    'administrator' => ['administrator', true, false],
    'read only' => ['read only', false, false],
]);

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
