<?php

declare(strict_types=1);

use App\Tenancy\Enums\Role;
use App\Tenancy\Models\TenantSetting;
use App\Tenancy\Policies\TenantSettingsPolicy;

covers(TenantSettingsPolicy::class);

test('owners and administrators can view and update firm settings', function (Role $role): void {
    ['tenant' => $tenant] = provisionWorkspace();
    $member = workspaceMember($tenant, $role);
    $settings = TenantSetting::query()->where('tenant_id', $tenant->id)->firstOrFail();
    $policy = resolve(TenantSettingsPolicy::class);

    actingInWorkspace($tenant);

    expect($policy->view($member, $settings))->toBeTrue()
        ->and($policy->update($member, $settings))->toBeTrue();
})->with([
    'owner' => [Role::Owner],
    'administrator' => [Role::Administrator],
]);

test('roles without manage settings permission cannot change firm settings', function (Role $role): void {
    ['tenant' => $tenant] = provisionWorkspace();
    $member = workspaceMember($tenant, $role);
    $settings = TenantSetting::query()->where('tenant_id', $tenant->id)->firstOrFail();
    $policy = resolve(TenantSettingsPolicy::class);

    actingInWorkspace($tenant);

    expect($policy->view($member, $settings))->toBeFalse()
        ->and($policy->update($member, $settings))->toBeFalse();
})->with([
    'adviser' => [Role::Adviser],
    'reviewer' => [Role::Reviewer],
    'read-only' => [Role::ReadOnly],
]);
