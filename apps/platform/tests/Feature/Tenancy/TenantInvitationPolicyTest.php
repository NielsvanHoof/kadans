<?php

declare(strict_types=1);

use App\Accounts\Models\User;
use App\Tenancy\Enums\Role;
use App\Tenancy\Models\TenantInvitation;
use App\Tenancy\Policies\TenantInvitationPolicy;

covers(TenantInvitationPolicy::class);

test('roles holding invite permission may create invitations', function (string $actor): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();

    $user = match ($actor) {
        'owner' => $owner,
        'administrator' => workspaceMember($tenant, Role::Administrator),
        default => throw new InvalidArgumentException("Unknown actor [{$actor}]."),
    };

    $policy = resolve(TenantInvitationPolicy::class);

    actingInWorkspace($tenant);

    expect($policy->create($user))->toBeTrue();
})->with([
    'owner' => 'owner',
    'administrator' => 'administrator',
]);

test('read-only members cannot create, resend, or revoke invitations', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();
    $reader = workspaceMember($tenant, Role::ReadOnly);

    $invitation = TenantInvitation::factory()->create([
        'tenant_id' => $tenant->id,
        'invited_by' => $owner->id,
    ]);

    $policy = resolve(TenantInvitationPolicy::class);

    actingInWorkspace($tenant);

    expect($policy->create($reader))->toBeFalse()
        ->and($policy->resend($reader, $invitation))->toBeFalse()
        ->and($policy->revoke($reader, $invitation))->toBeFalse();
});

test('owners can resend pending invitations and revoke unaccepted ones', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();

    $pending = TenantInvitation::factory()->create([
        'tenant_id' => $tenant->id,
        'invited_by' => $owner->id,
    ]);
    $accepted = TenantInvitation::factory()->accepted()->create([
        'tenant_id' => $tenant->id,
        'invited_by' => $owner->id,
    ]);

    $policy = resolve(TenantInvitationPolicy::class);

    expect($policy->resend($owner, $pending))->toBeTrue()
        ->and($policy->revoke($owner, $pending))->toBeTrue()
        ->and($policy->resend($owner, $accepted))->toBeFalse()
        ->and($policy->revoke($owner, $accepted))->toBeFalse();
});

test('owners cannot manage invitations that belong to another tenant', function (): void {
    ['owner' => $owner] = provisionWorkspace();
    ['owner' => $otherOwner, 'tenant' => $otherTenant] = provisionWorkspace('Other firm');

    $foreignInvitation = TenantInvitation::factory()->create([
        'tenant_id' => $otherTenant->id,
        'invited_by' => $otherOwner->id,
    ]);

    $policy = resolve(TenantInvitationPolicy::class);

    expect($policy->resend($owner, $foreignInvitation))->toBeFalse()
        ->and($policy->revoke($owner, $foreignInvitation))->toBeFalse();
});

test('users outside the tenant cannot create invitations', function (): void {
    ['tenant' => $tenant] = provisionWorkspace();
    $outsider = User::factory()->create();
    $policy = resolve(TenantInvitationPolicy::class);

    actingInWorkspace($tenant);

    expect($policy->create($outsider))->toBeFalse();
});
