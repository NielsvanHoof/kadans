<?php

declare(strict_types=1);

use App\Clients\Models\Client;
use App\Dossiers\Enums\DossierStatus;
use App\Dossiers\Models\Dossier;
use App\Tenancy\Enums\Role;

test('sync is forbidden without an active builder lock', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();

    $client = Client::factory()->create(['tenant_id' => $tenant->id]);
    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
        'status' => DossierStatus::Draft,
    ]);

    $this->actingAs($owner)
        ->putJson(workspaceRoute('workspaces.dossiers.document-requests.sync', $tenant, [
            'dossier' => $dossier,
        ]), [
            'items' => [],
        ])
        ->assertForbidden();
});

test('sync is forbidden when another adviser holds the lock', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();

    $client = Client::factory()->create(['tenant_id' => $tenant->id]);
    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
        'status' => DossierStatus::Draft,
    ]);

    grantDossierBuilderLock($dossier, $owner);

    $adviser = workspaceMember($tenant, Role::Adviser);

    $this->actingAs($adviser)
        ->putJson(workspaceRoute('workspaces.dossiers.document-requests.sync', $tenant, [
            'dossier' => $dossier,
        ]), [
            'items' => [],
        ])
        ->assertForbidden();
});
