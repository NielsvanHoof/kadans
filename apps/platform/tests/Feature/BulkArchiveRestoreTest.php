<?php

declare(strict_types=1);

use App\Clients\Models\Client;
use App\Dossiers\Models\Dossier;
use App\Tenancy\Enums\Role;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

pest()
    ->use(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
});

test('owner can bulk archive clients and cascade their dossiers', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();
    $clientA = Client::factory()->create(['tenant_id' => $tenant->id]);
    $clientB = Client::factory()->create(['tenant_id' => $tenant->id]);
    $dossierA = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $clientA->id,
    ]);
    $dossierB = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $clientB->id,
    ]);

    $this->actingAs($owner)
        ->withSession(['active_tenant_id' => $tenant->id])
        ->post(workspaceRoute('workspaces.clients.bulk-archive', $tenant), [
            'ids' => [$clientA->id, $clientB->id],
        ])
        ->assertRedirect(workspaceRoute('workspaces.clients.index', $tenant));

    expect($clientA->fresh()->trashed())->toBeTrue()
        ->and($clientB->fresh()->trashed())->toBeTrue()
        ->and($dossierA->fresh()->trashed())->toBeTrue()
        ->and($dossierB->fresh()->trashed())->toBeTrue();
});

test('owner can bulk restore clients without restoring dossiers', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();
    $clientA = Client::factory()->create(['tenant_id' => $tenant->id]);
    $clientB = Client::factory()->create(['tenant_id' => $tenant->id]);
    $dossierA = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $clientA->id,
    ]);
    $dossierB = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $clientB->id,
    ]);
    $dossierA->delete();
    $dossierB->delete();
    $clientA->delete();
    $clientB->delete();

    $this->actingAs($owner)
        ->withSession(['active_tenant_id' => $tenant->id])
        ->post(workspaceRoute('workspaces.clients.bulk-restore', $tenant), [
            'ids' => [$clientA->id, $clientB->id],
        ])
        ->assertRedirect(workspaceRoute('workspaces.clients.archived', $tenant));

    expect($clientA->fresh()->trashed())->toBeFalse()
        ->and($clientB->fresh()->trashed())->toBeFalse()
        ->and($dossierA->fresh()->trashed())->toBeTrue()
        ->and($dossierB->fresh()->trashed())->toBeTrue();
});

test('owner can bulk archive dossiers', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();
    $client = Client::factory()->create(['tenant_id' => $tenant->id]);
    $dossierA = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
    ]);
    $dossierB = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
    ]);

    $this->actingAs($owner)
        ->withSession(['active_tenant_id' => $tenant->id])
        ->post(workspaceRoute('workspaces.dossiers.bulk-archive', $tenant), [
            'ids' => [$dossierA->id, $dossierB->id],
        ])
        ->assertRedirect(workspaceRoute('workspaces.dossiers.index', $tenant));

    expect($dossierA->fresh()->trashed())->toBeTrue()
        ->and($dossierB->fresh()->trashed())->toBeTrue()
        ->and($client->fresh()->trashed())->toBeFalse();
});

test('owner can bulk restore dossiers when clients are active', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();
    $client = Client::factory()->create(['tenant_id' => $tenant->id]);
    $dossierA = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
    ]);
    $dossierB = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
    ]);
    $dossierA->delete();
    $dossierB->delete();

    $this->actingAs($owner)
        ->withSession(['active_tenant_id' => $tenant->id])
        ->post(workspaceRoute('workspaces.dossiers.bulk-restore', $tenant), [
            'ids' => [$dossierA->id, $dossierB->id],
        ])
        ->assertRedirect(workspaceRoute('workspaces.dossiers.archived', $tenant));

    expect($dossierA->fresh()->trashed())->toBeFalse()
        ->and($dossierB->fresh()->trashed())->toBeFalse();
});

test('bulk restore dossiers fails when a selected dossier client is archived', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();
    $client = Client::factory()->create(['tenant_id' => $tenant->id]);
    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
    ]);
    $dossier->delete();
    $client->delete();

    $this->actingAs($owner)
        ->withSession(['active_tenant_id' => $tenant->id])
        ->from(workspaceRoute('workspaces.dossiers.archived', $tenant))
        ->post(workspaceRoute('workspaces.dossiers.bulk-restore', $tenant), [
            'ids' => [$dossier->id],
        ])
        ->assertSessionHasErrors('ids');

    expect($dossier->fresh()->trashed())->toBeTrue();
});

test('bulk archive rejects ids from another workspace', function (): void {
    ['tenant' => $tenant] = provisionWorkspace();
    $foreignClient = Client::factory()->create(['tenant_id' => $tenant->id]);
    ['owner' => $otherOwner, 'tenant' => $otherTenant] = provisionWorkspace();

    $this->actingAs($otherOwner)
        ->withSession(['active_tenant_id' => $otherTenant->id])
        ->post(workspaceRoute('workspaces.clients.bulk-archive', $otherTenant), [
            'ids' => [$foreignClient->id],
        ])
        ->assertSessionHasErrors('ids.0');

    $tenant->makeCurrent();
    expect($foreignClient->fresh()->trashed())->toBeFalse();
});

test('read only members cannot bulk archive or restore', function (): void {
    ['tenant' => $tenant] = provisionWorkspace();
    $reader = workspaceMember($tenant, Role::ReadOnly);
    $client = Client::factory()->create(['tenant_id' => $tenant->id]);
    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
    ]);
    $dossier->delete();
    $client->delete();

    $this->actingAs($reader)
        ->withSession(['active_tenant_id' => $tenant->id])
        ->post(workspaceRoute('workspaces.clients.bulk-archive', $tenant), [
            'ids' => [$client->id],
        ])
        ->assertForbidden();

    $this->post(workspaceRoute('workspaces.clients.bulk-restore', $tenant), [
        'ids' => [$client->id],
    ])->assertForbidden();

    $this->post(workspaceRoute('workspaces.dossiers.bulk-archive', $tenant), [
        'ids' => [$dossier->id],
    ])->assertForbidden();

    $this->post(workspaceRoute('workspaces.dossiers.bulk-restore', $tenant), [
        'ids' => [$dossier->id],
    ])->assertForbidden();
});
