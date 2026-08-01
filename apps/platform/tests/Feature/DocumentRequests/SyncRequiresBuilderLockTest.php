<?php

declare(strict_types=1);

use App\Accounts\Models\User;
use App\Clients\Models\Client;
use App\Dossiers\Enums\DossierStatus;
use App\Dossiers\Models\Dossier;
use App\Tenancy\Actions\ProvisionTenantAction;
use App\Tenancy\Enums\Role;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
});

test('sync is forbidden without an active builder lock', function (): void {
    $owner = User::factory()->create();
    $tenant = resolve(ProvisionTenantAction::class)->handle('Acme Accountants', $owner);

    $tenant->makeCurrent();
    setPermissionsTeamId($tenant->id);

    $client = Client::factory()->create(['tenant_id' => $tenant->id]);
    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
        'status' => DossierStatus::Draft,
    ]);

    $this->actingAs($owner)
        ->withSession(['active_tenant_id' => $tenant->id])
        ->putJson(workspaceRoute('workspaces.dossiers.document-requests.sync', $tenant, [
            'dossier' => $dossier,
        ]), [
            'items' => [],
        ])
        ->assertForbidden();
});

test('sync is forbidden when another adviser holds the lock', function (): void {
    $owner = User::factory()->create();
    $tenant = resolve(ProvisionTenantAction::class)->handle('Acme Accountants', $owner);

    $tenant->makeCurrent();
    setPermissionsTeamId($tenant->id);

    $client = Client::factory()->create(['tenant_id' => $tenant->id]);
    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
        'status' => DossierStatus::Draft,
    ]);

    grantDossierBuilderLock($dossier, $owner);

    $adviser = workspaceMember($tenant, Role::Adviser);

    $this->actingAs($adviser)
        ->withSession(['active_tenant_id' => $tenant->id])
        ->putJson(workspaceRoute('workspaces.dossiers.document-requests.sync', $tenant, [
            'dossier' => $dossier,
        ]), [
            'items' => [],
        ])
        ->assertForbidden();
});
