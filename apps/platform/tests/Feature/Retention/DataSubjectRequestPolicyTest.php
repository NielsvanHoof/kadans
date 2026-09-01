<?php

declare(strict_types=1);

use App\Clients\Models\Client;
use App\Platform\Actions\DataSubject\LogDataSubjectRequestAction;
use App\Platform\Enums\DataSubjectPermission;
use App\Platform\Enums\DataSubjectRequestStatus;
use App\Platform\Enums\DataSubjectRequestType;
use App\Platform\Policies\DataSubjectRequestPolicy;
use App\Tenancy\Enums\Role;

covers(DataSubjectRequestPolicy::class);

/**
 * @return array{owner: mixed, tenant: mixed, client: Client}
 */
function dataSubjectPolicyFixture(): array
{
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();

    $client = Client::factory()->create(['tenant_id' => $tenant->id]);

    return ['owner' => $owner, 'tenant' => $tenant, 'client' => $client];
}

test('owners can manage every open data-subject request action', function (): void {
    ['owner' => $owner, 'client' => $client] = dataSubjectPolicyFixture();

    $request = resolve(LogDataSubjectRequestAction::class)
        ->handle($client, $owner, DataSubjectRequestType::Erasure);
    $policy = resolve(DataSubjectRequestPolicy::class);

    actingInWorkspace($request->tenant);

    expect($policy->viewAny($owner))->toBeTrue()
        ->and($policy->create($owner))->toBeTrue()
        ->and($policy->fulfil($owner, $request))->toBeTrue()
        ->and($policy->refuse($owner, $request))->toBeTrue();
});

test('roles without data-subject permissions cannot manage requests', function (Role $role): void {
    ['tenant' => $tenant, 'client' => $client, 'owner' => $owner] = dataSubjectPolicyFixture();
    $member = workspaceMember($tenant, $role);

    $request = resolve(LogDataSubjectRequestAction::class)
        ->handle($client, $owner, DataSubjectRequestType::Export);
    $policy = resolve(DataSubjectRequestPolicy::class);

    actingInWorkspace($tenant);

    expect($policy->viewAny($member))->toBeFalse()
        ->and($policy->create($member))->toBeFalse()
        ->and($policy->fulfil($member, $request))->toBeFalse()
        ->and($policy->refuse($member, $request))->toBeFalse();
})->with([
    'administrator' => [Role::Administrator],
    'adviser' => [Role::Adviser],
    'read-only' => [Role::ReadOnly],
]);

test('view permission allows reading but not fulfilling or refusing', function (): void {
    ['tenant' => $tenant, 'client' => $client, 'owner' => $owner] = dataSubjectPolicyFixture();
    $member = workspaceMember($tenant, Role::Administrator);
    $member->givePermissionTo(DataSubjectPermission::View->value);

    $request = resolve(LogDataSubjectRequestAction::class)
        ->handle($client, $owner, DataSubjectRequestType::Erasure);
    $policy = resolve(DataSubjectRequestPolicy::class);

    actingInWorkspace($tenant);

    expect($policy->viewAny($member))->toBeTrue()
        ->and($policy->create($member))->toBeFalse()
        ->and($policy->fulfil($member, $request))->toBeFalse()
        ->and($policy->refuse($member, $request))->toBeFalse();
});

test('closed requests cannot be fulfilled or refused', function (): void {
    ['owner' => $owner, 'client' => $client] = dataSubjectPolicyFixture();

    $request = resolve(LogDataSubjectRequestAction::class)
        ->handle($client, $owner, DataSubjectRequestType::Erasure);
    $request->forceFill(['status' => DataSubjectRequestStatus::Completed])->save();
    $policy = resolve(DataSubjectRequestPolicy::class);

    actingInWorkspace($request->tenant);

    expect($policy->fulfil($owner, $request))->toBeFalse()
        ->and($policy->refuse($owner, $request))->toBeFalse();
});
