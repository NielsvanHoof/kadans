<?php

declare(strict_types=1);

use App\ClientPortal\Models\ClientAccessGrant;
use App\Clients\Models\Client;
use App\Dossiers\Models\Dossier;
use App\Tenancy\Models\TenantInvitation;

beforeEach(function (): void {
    config([
        'retention.portal_grants_days' => 90,
    ]);
});

test('model prune discovers prunable models across domain modules', function (): void {
    $this->artisan('model:prune', ['--pretend' => true])
        ->expectsOutputToContain(ClientAccessGrant::class)
        ->expectsOutputToContain(TenantInvitation::class)
        ->assertSuccessful();
});

test('expired and revoked portal grants are prunable after the retention period', function (): void {
    ['tenant' => $tenant] = provisionWorkspace();

    $client = Client::factory()->create(['tenant_id' => $tenant->id]);
    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
    ]);

    $expiredGrant = ClientAccessGrant::factory()->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $dossier->id,
        'expires_at' => now()->subDays(120),
        'revoked_at' => null,
    ]);

    $revokedGrant = ClientAccessGrant::factory()->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $dossier->id,
        'expires_at' => now()->addDays(7),
        'revoked_at' => now()->subDays(120),
    ]);

    $activeGrant = ClientAccessGrant::factory()->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $dossier->id,
        'expires_at' => now()->addDays(7),
        'revoked_at' => null,
    ]);

    $this->artisan('model:prune', [
        '--model' => [ClientAccessGrant::class],
    ])->assertSuccessful();

    expect(ClientAccessGrant::query()->whereKey($expiredGrant->id)->exists())->toBeFalse()
        ->and(ClientAccessGrant::query()->whereKey($revokedGrant->id)->exists())->toBeFalse()
        ->and(ClientAccessGrant::query()->whereKey($activeGrant->id)->exists())->toBeTrue();
});

test('model prune pretend reports prunable portal grants without deleting them', function (): void {
    ['tenant' => $tenant] = provisionWorkspace();

    $client = Client::factory()->create(['tenant_id' => $tenant->id]);
    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
    ]);

    ClientAccessGrant::factory()->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $dossier->id,
        'expires_at' => now()->subDays(120),
        'revoked_at' => null,
    ]);

    $this->artisan('model:prune', [
        '--model' => [ClientAccessGrant::class],
        '--pretend' => true,
    ])
        ->expectsOutputToContain('ClientAccessGrant')
        ->assertSuccessful();

    expect(ClientAccessGrant::query()->count())->toBe(1);
});
