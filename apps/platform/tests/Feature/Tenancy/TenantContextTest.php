<?php

declare(strict_types=1);

use App\Tenancy\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

pest()
    ->use(RefreshDatabase::class);

test('tenant context runs callbacks under the given tenant and restores afterward', function (): void {
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
