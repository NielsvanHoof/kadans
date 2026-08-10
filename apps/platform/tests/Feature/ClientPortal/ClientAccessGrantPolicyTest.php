<?php

declare(strict_types=1);

use App\Accounts\Models\User;
use App\ClientPortal\Models\ClientAccessGrant;
use App\ClientPortal\Policies\ClientAccessGrantPolicy;
use App\Clients\Models\Client;
use App\Dossiers\Models\Dossier;
use App\Tenancy\Enums\Role;
use App\Tenancy\Models\Tenant;

covers(ClientAccessGrantPolicy::class);

/**
 * @return array{owner: User, tenant: Tenant, grant: ClientAccessGrant}
 */
function clientAccessGrantPolicyContext(): array
{
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();

    $client = Client::factory()->create(['tenant_id' => $tenant->id]);
    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
    ]);
    $grant = ClientAccessGrant::factory()->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $dossier->id,
        'created_by' => $owner->id,
    ]);

    return [
        'owner' => $owner,
        'tenant' => $tenant,
        'grant' => $grant,
    ];
}

test('roles holding portal grant permission may create access grants', function (string $actor): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();

    $user = match ($actor) {
        'owner' => $owner,
        'adviser' => workspaceMember($tenant, Role::Adviser),
        default => throw new InvalidArgumentException("Unknown actor [{$actor}]."),
    };

    $policy = resolve(ClientAccessGrantPolicy::class);

    actingInWorkspace($tenant);

    expect($policy->create($user))->toBeTrue();
})->with([
    'owner' => 'owner',
    'adviser' => 'adviser',
]);

test('read-only members cannot grant or revoke portal access', function (): void {
    [
        'tenant' => $tenant,
        'grant' => $grant,
    ] = clientAccessGrantPolicyContext();
    $reader = workspaceMember($tenant, Role::ReadOnly);
    $policy = resolve(ClientAccessGrantPolicy::class);

    actingInWorkspace($tenant);

    expect($policy->create($reader))->toBeFalse()
        ->and($policy->revoke($reader, $grant))->toBeFalse();
});

test('owners can revoke a grant that belongs to their tenant', function (): void {
    [
        'owner' => $owner,
        'grant' => $grant,
    ] = clientAccessGrantPolicyContext();

    expect(resolve(ClientAccessGrantPolicy::class)->revoke($owner, $grant))->toBeTrue();
});

test('owners cannot revoke a grant that belongs to another tenant', function (): void {
    ['owner' => $owner] = provisionWorkspace();
    ['owner' => $otherOwner, 'tenant' => $otherTenant] = provisionWorkspace('Other firm');

    $client = Client::factory()->create(['tenant_id' => $otherTenant->id]);
    $dossier = Dossier::factory()->create([
        'tenant_id' => $otherTenant->id,
        'client_id' => $client->id,
    ]);
    $foreignGrant = ClientAccessGrant::factory()->create([
        'tenant_id' => $otherTenant->id,
        'dossier_id' => $dossier->id,
        'created_by' => $otherOwner->id,
    ]);

    expect(resolve(ClientAccessGrantPolicy::class)->revoke($owner, $foreignGrant))->toBeFalse();
});

test('users outside the tenant cannot grant portal access', function (): void {
    ['tenant' => $tenant] = provisionWorkspace();
    $outsider = User::factory()->create();
    $policy = resolve(ClientAccessGrantPolicy::class);

    actingInWorkspace($tenant);

    expect($policy->create($outsider))->toBeFalse();
});
