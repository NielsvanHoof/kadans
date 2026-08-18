<?php

declare(strict_types=1);

use App\Audit\Policies\ActivityPolicy;
use App\Tenancy\Enums\Role;

covers(ActivityPolicy::class);

test('owner can view the activity log', function (): void {
    ['owner' => $owner] = provisionWorkspace();
    $policy = new ActivityPolicy;

    expect($policy->viewAny($owner))->toBeTrue();
});

test('roles without activity view cannot view the activity log', function (Role $role): void {
    ['tenant' => $tenant] = provisionWorkspace();
    $member = workspaceMember($tenant, $role);
    $policy = new ActivityPolicy;

    expect($policy->viewAny($member))->toBeFalse();
})->with([
    'reviewer' => Role::Reviewer,
    'read only' => Role::ReadOnly,
]);
