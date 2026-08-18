<?php

declare(strict_types=1);

use App\Clients\Models\Client;
use App\Tenancy\Models\Tenant;
use App\Tenancy\TenantContext;

test('without a tenant soft scope returns no rows instead of throwing', function (): void {
    ['tenant' => $tenant] = provisionWorkspace('Scoped Firm');

    Client::factory()->create(['tenant_id' => $tenant->id]);

    Tenant::forgetCurrent();

    expect(Client::query()->count())->toBe(0);
});

test('strict mode throws when querying without a current tenant', function (): void {
    resolve(TenantContext::class)->enableStrict();

    expect(fn () => Client::query()->count())
        ->toThrow(RuntimeException::class, 'Cannot query tenant-owned records without an active tenant.');
});

test('queryWithoutTenantScope allows intentional cross-tenant lookups', function (): void {
    ['tenant' => $tenant] = provisionWorkspace('Lookup Firm');

    $client = Client::factory()->create(['tenant_id' => $tenant->id]);

    Tenant::forgetCurrent();

    expect(Client::queryWithoutTenantScope()->whereKey($client->id)->exists())->toBeTrue()
        ->and(Client::query()->whereKey($client->id)->exists())->toBeFalse();
});

test('runForTenant enables strict scoping for the callback duration', function (): void {
    // Bare tenant: runForTenant must work without a provisioned workspace graph.
    $tenant = Tenant::factory()->create();
    $context = resolve(TenantContext::class);

    expect($context->isStrict())->toBeFalse();

    $context->runForTenant($tenant, function () use ($context, $tenant): void {
        expect($context->isStrict())->toBeTrue()
            ->and(Tenant::current()?->id)->toBe($tenant->id);
    });

    expect($context->isStrict())->toBeFalse()
        ->and(Tenant::current())->toBeNull();
});

test('creating without a tenant_id stamps the current tenant', function (): void {
    ['tenant' => $tenant] = provisionWorkspace('Stamp Firm');

    $client = Client::query()->create([
        'name' => 'Stamped Client',
        'email' => 'stamped@example.com',
    ]);

    expect($client->tenant_id)->toBe($tenant->id);
});

test('creating with a matching tenant_id is allowed', function (): void {
    ['tenant' => $tenant] = provisionWorkspace('Match Firm');

    $client = Client::factory()->create(['tenant_id' => $tenant->id]);

    expect($client->tenant_id)->toBe($tenant->id);
});

test('creating with a different tenant_id is rejected', function (): void {
    ['tenant' => $current] = provisionWorkspace('Current Firm');
    ['tenant' => $other] = provisionWorkspace('Other Firm');

    actingInWorkspace($current);

    expect(fn () => Client::factory()->create(['tenant_id' => $other->id]))
        ->toThrow(RuntimeException::class, 'Cannot create a tenant-owned record for a different tenant than the current one.');
});

test('tenant_id cannot be changed after create', function (): void {
    ['tenant' => $tenant] = provisionWorkspace('Immutable Firm');
    ['tenant' => $other] = provisionWorkspace('Other Immutable Firm');

    actingInWorkspace($tenant);
    $client = Client::factory()->create(['tenant_id' => $tenant->id]);

    expect(fn () => $client->update(['tenant_id' => $other->id]))
        ->toThrow(RuntimeException::class, 'tenant_id cannot be changed after create.');
});
