<?php

declare(strict_types=1);

use App\Accounts\Models\User;
use App\Clients\Models\Client;
use App\Clients\Policies\ClientPolicy;
use App\Tenancy\Enums\Role;

test('owner can viewAny, view, create, update, restore, and delete clients', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();

    $client = Client::factory()->create(['tenant_id' => $tenant->id]);
    $policy = new ClientPolicy;

    expect($policy->viewAny($owner))->toBeTrue()
        ->and($policy->view($owner, $client))->toBeTrue()
        ->and($policy->create($owner))->toBeTrue()
        ->and($policy->update($owner, $client))->toBeTrue()
        ->and($policy->restore($owner, $client))->toBeTrue()
        ->and($policy->delete($owner, $client))->toBeTrue();
});

test('read-only members cannot manage clients', function (): void {
    ['tenant' => $tenant] = provisionWorkspace();
    $reader = workspaceMember($tenant, Role::ReadOnly);

    $client = Client::factory()->create(['tenant_id' => $tenant->id]);
    $policy = new ClientPolicy;

    expect($policy->viewAny($reader))->toBeFalse()
        ->and($policy->view($reader, $client))->toBeFalse()
        ->and($policy->create($reader))->toBeFalse()
        ->and($policy->update($reader, $client))->toBeFalse()
        ->and($policy->restore($reader, $client))->toBeFalse()
        ->and($policy->delete($reader, $client))->toBeFalse();
});

test('users outside the tenant cannot view a client', function (): void {
    ['tenant' => $tenant] = provisionWorkspace();

    $client = Client::factory()->create(['tenant_id' => $tenant->id]);
    $outsider = User::factory()->create();
    $policy = new ClientPolicy;

    expect($policy->view($outsider, $client))->toBeFalse();
});
