<?php

declare(strict_types=1);

use App\Tenancy\Actions\ProvisionTenantAction;
use App\Tenancy\Support\TenantHostname;
use Database\Seeders\RolesAndPermissionsSeeder;

covers(TenantHostname::class);

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
});

test('tenant hostname helpers resolve central and tenant hosts', function (): void {
    $hostname = resolve(TenantHostname::class);

    expect($hostname->baseDomain())->toBe('qompose.test')
        ->and($hostname->isCentralHost('app.qompose.test'))->toBeTrue()
        ->and($hostname->tenantSlugFromHost('acme.qompose.test'))->toBe('acme')
        ->and($hostname->tenantSlugFromHost('app.qompose.test'))->toBeNull()
        ->and($hostname->tenantSlugFromHost('www.qompose.test'))->toBeNull()
        ->and($hostname->isReservedSubdomain('mail'))->toBeTrue()
        ->and($hostname->isValidTenantSlug('mail'))->toBeFalse()
        ->and($hostname->isValidTenantSlug('acme'))->toBeTrue()
        ->and($hostname->parentSessionDomain())->toBe('.qompose.test');
});

test('provisioning skips reserved subdomain slugs', function (): void {
    $owner = App\Accounts\Models\User::factory()->create();

    $tenant = resolve(ProvisionTenantAction::class)->handle('App', $owner);

    expect($tenant->slug)->not->toBe('app')
        ->and(resolve(TenantHostname::class)->isValidTenantSlug($tenant->slug))->toBeTrue();
});

test('unknown tenant hosts return not found', function (): void {
    ['owner' => $owner] = provisionWorkspace();

    $this->actingAs($owner)
        ->get('http://missing-firm.qompose.test/dashboard')
        ->assertNotFound();
});

test('reserved subdomain hosts are rejected for workspace routes', function (): void {
    ['owner' => $owner] = provisionWorkspace();

    $this->actingAs($owner)
        ->get('http://mail.qompose.test/dashboard')
        ->assertNotFound();
});
