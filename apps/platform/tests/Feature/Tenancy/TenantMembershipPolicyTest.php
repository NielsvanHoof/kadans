<?php

declare(strict_types=1);

use App\Tenancy\Enums\Role;
use App\Tenancy\Enums\TenantMembershipStatus;
use App\Tenancy\Models\TenantMembership;
use App\Tenancy\Policies\TenantMembershipPolicy;

test('members with manage permission can update memberships', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();
    $member = workspaceMember($tenant, Role::Adviser);
    $membership = TenantMembership::query()
        ->where('tenant_id', $tenant->id)
        ->where('user_id', $member->id)
        ->firstOrFail();

    $policy = resolve(TenantMembershipPolicy::class);

    actingInWorkspace($tenant);

    expect($policy->viewAny($owner))->toBeTrue()
        ->and($policy->update($owner, $membership))->toBeTrue()
        ->and($policy->suspend($owner, $membership))->toBeTrue()
        ->and($policy->remove($owner, $membership))->toBeTrue()
        ->and($policy->assignRole($owner, $membership, Role::Owner))->toBeTrue();
});

test('read-only users cannot manage memberships', function (): void {
    ['tenant' => $tenant] = provisionWorkspace();
    $reader = workspaceMember($tenant, Role::ReadOnly);
    $member = workspaceMember($tenant, Role::Adviser);
    $membership = TenantMembership::query()
        ->where('tenant_id', $tenant->id)
        ->where('user_id', $member->id)
        ->firstOrFail();

    $policy = resolve(TenantMembershipPolicy::class);

    actingInWorkspace($tenant);

    expect($policy->viewAny($reader))->toBeFalse()
        ->and($policy->update($reader, $membership))->toBeFalse();
});

test('administrators cannot assign the owner role', function (): void {
    ['tenant' => $tenant] = provisionWorkspace();
    $admin = workspaceMember($tenant, Role::Administrator);
    $member = workspaceMember($tenant, Role::Adviser);
    $membership = TenantMembership::query()
        ->where('tenant_id', $tenant->id)
        ->where('user_id', $member->id)
        ->firstOrFail();

    $policy = resolve(TenantMembershipPolicy::class);

    actingInWorkspace($tenant);

    expect($policy->assignRole($admin, $membership, Role::Owner))->toBeFalse()
        ->and($policy->assignRole($admin, $membership, Role::Reviewer))->toBeTrue();
});

test('the last owner cannot remove themselves', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();
    $membership = TenantMembership::query()
        ->where('tenant_id', $tenant->id)
        ->where('user_id', $owner->id)
        ->firstOrFail();

    $policy = resolve(TenantMembershipPolicy::class);

    actingInWorkspace($tenant);

    expect($policy->remove($owner, $membership))->toBeFalse()
        ->and($membership->status)->toBe(TenantMembershipStatus::Active);
});
