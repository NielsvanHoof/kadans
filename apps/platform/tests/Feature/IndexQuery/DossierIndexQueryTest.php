<?php

declare(strict_types=1);

use App\Accounts\Models\User;
use App\Clients\Models\Client;
use App\Dossiers\Enums\DossierStatus;
use App\Dossiers\Models\Dossier;
use App\Tenancy\Actions\ProvisionTenantAction;
use Database\Seeders\RolesAndPermissionsSeeder;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
});

test('dossiers index filters by status and search', function (): void {
    $owner = User::factory()->create();
    $tenant = resolve(ProvisionTenantAction::class)->handle('Acme Accountants', $owner);

    $tenant->makeCurrent();

    $client = Client::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Jane Client',
    ]);

    Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
        'title' => 'Payroll 2025',
        'reference' => 'PAY-001',
        'status' => DossierStatus::Draft,
    ]);
    Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
        'title' => 'Annual accounts',
        'reference' => 'ANN-001',
        'status' => DossierStatus::InReview,
    ]);

    $this->actingAs($owner)
        ->withSession(['active_tenant_id' => $tenant->id])
        ->get(workspaceRoute('workspaces.dossiers.index', $tenant).'?filter[status]=draft')
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('dossiers/index')
            ->has('dossiers.data', 1)
            ->where('dossiers.data.0.title', 'Payroll 2025')
            ->where('dossiers.data.0.status', 'draft')
            ->has('indexQuery'));

    $this->actingAs($owner)
        ->withSession(['active_tenant_id' => $tenant->id])
        ->get(workspaceRoute('workspaces.dossiers.index', $tenant).'?filter[q]=Payroll')
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->has('dossiers.data', 1)
            ->where('dossiers.data.0.reference', 'PAY-001'));
});

test('dossiers index sorts by title ascending', function (): void {
    $owner = User::factory()->create();
    $tenant = resolve(ProvisionTenantAction::class)->handle('Acme Accountants', $owner);

    $tenant->makeCurrent();
    $client = Client::factory()->create(['tenant_id' => $tenant->id]);

    Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
        'title' => 'Zebra dossier',
    ]);
    Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
        'title' => 'Alpha dossier',
    ]);

    $this->actingAs($owner)
        ->withSession(['active_tenant_id' => $tenant->id])
        ->get(workspaceRoute('workspaces.dossiers.index', $tenant).'?sort=title')
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('dossiers.data.0.title', 'Alpha dossier')
            ->where('dossiers.data.1.title', 'Zebra dossier'));
});

test('dossiers index rejects invalid sort', function (): void {
    $owner = User::factory()->create();
    $tenant = resolve(ProvisionTenantAction::class)->handle('Acme Accountants', $owner);

    $this->actingAs($owner)
        ->withSession(['active_tenant_id' => $tenant->id])
        ->get(workspaceRoute('workspaces.dossiers.index', $tenant).'?sort=password')
        ->assertStatus(400);
});
