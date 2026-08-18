<?php

declare(strict_types=1);

use App\Accounts\Models\User;
use App\Clients\Models\Client;
use App\Clients\Policies\ClientPolicy;
use App\Tenancy\Enums\Role;

covers(ClientPolicy::class);

test('client management abilities match the actor', function (string $actor, bool $allowed): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();

    $user = match ($actor) {
        'owner' => $owner,
        'read only' => workspaceMember($tenant, Role::ReadOnly),
        default => throw new InvalidArgumentException("Unknown actor [{$actor}]."),
    };

    $client = Client::factory()->create(['tenant_id' => $tenant->id]);
    $policy = new ClientPolicy;

    expect($policy->viewAny($user))->toBe($allowed)
        ->and($policy->view($user, $client))->toBe($allowed)
        ->and($policy->create($user))->toBe($allowed)
        ->and($policy->update($user, $client))->toBe($allowed)
        ->and($policy->restore($user, $client))->toBe($allowed)
        ->and($policy->delete($user, $client))->toBe($allowed);
})->with([
    'owner' => ['owner', true],
    'read only' => ['read only', false],
]);

test('users outside the tenant cannot view a client', function (): void {
    ['tenant' => $tenant] = provisionWorkspace();

    $client = Client::factory()->create(['tenant_id' => $tenant->id]);
    $outsider = User::factory()->create();
    $policy = new ClientPolicy;

    expect($policy->view($outsider, $client))->toBeFalse();
});
