<?php

declare(strict_types=1);

use App\Audit\Policies\ActivityPolicy;
use App\Tenancy\Enums\Role;

test('owner can view the activity log', function (): void {
    ['owner' => $owner] = provisionWorkspace();
    $policy = new ActivityPolicy;

    expect($policy->viewAny($owner))->toBeTrue();
});

test('reviewers cannot view the activity log', function (): void {
    ['tenant' => $tenant] = provisionWorkspace();
    $reviewer = workspaceMember($tenant, Role::Reviewer);
    $policy = new ActivityPolicy;

    expect($policy->viewAny($reviewer))->toBeFalse();
});

test('read-only members cannot view the activity log', function (): void {
    ['tenant' => $tenant] = provisionWorkspace();
    $reader = workspaceMember($tenant, Role::ReadOnly);
    $policy = new ActivityPolicy;

    expect($policy->viewAny($reader))->toBeFalse();
});
