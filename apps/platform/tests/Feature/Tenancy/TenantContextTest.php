<?php

declare(strict_types=1);

use App\Tenancy\Models\Tenant;
use App\Tenancy\TenantContext;

test('tenant context runs callbacks under the given tenant and restores afterward', function (): void {
    // Bare tenants on purpose: this exercises TenantContext::runForTenant restore,
    // not workspace roles — provisionWorkspace would obscure the makeCurrent baseline.
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    $tenantA->makeCurrent();

    $seenTenantId = resolve(TenantContext::class)->runForTenant($tenantB, function () use ($tenantB): int {
        expect(Tenant::current()?->id)->toBe($tenantB->id);

        return $tenantB->id;
    });

    expect($seenTenantId)->toBe($tenantB->id)
        ->and(Tenant::current()?->id)->toBe($tenantA->id);
});

test('tenant context strict flag is restored after runStrict', function (): void {
    $context = resolve(TenantContext::class);

    expect($context->isStrict())->toBeFalse();

    $context->runStrict(function () use ($context): void {
        expect($context->isStrict())->toBeTrue();
    });

    expect($context->isStrict())->toBeFalse();

    $context->enableStrict();

    $context->runStrict(function () use ($context): void {
        expect($context->isStrict())->toBeTrue();
    });

    expect($context->isStrict())->toBeTrue();
});
