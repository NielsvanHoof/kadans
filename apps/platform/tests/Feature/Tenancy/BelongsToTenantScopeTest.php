<?php

declare(strict_types=1);

use App\Clients\Models\Client;
use App\Tenancy\Actions\ProvisionTenantAction;
use App\Tenancy\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

pest()
    ->use(RefreshDatabase::class);

test('without a tenant soft scope returns no rows instead of throwing', function (): void {
    $owner = provisionWorkspace()['owner'];
    $tenant = resolve(ProvisionTenantAction::class)->handle('Scoped Firm', $owner);
    $tenant->makeCurrent();

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
    $owner = provisionWorkspace()['owner'];
    $tenant = resolve(ProvisionTenantAction::class)->handle('Lookup Firm', $owner);
    $tenant->makeCurrent();

    $client = Client::factory()->create(['tenant_id' => $tenant->id]);

    Tenant::forgetCurrent();

    expect(Client::queryWithoutTenantScope()->whereKey($client->id)->exists())->toBeTrue()
        ->and(Client::query()->whereKey($client->id)->exists())->toBeFalse();
});

test('runForTenant enables strict scoping for the callback duration', function (): void {
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
