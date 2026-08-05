<?php

declare(strict_types=1);

use App\Clients\Models\Client;
use App\Dossiers\Enums\DossierStatus;
use App\Dossiers\Models\Dossier;
use App\Dossiers\Queries\FetchDossierIndexQuery;
use Database\Seeders\RolesAndPermissionsSeeder;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
});

test('dossier index toolbar metadata includes workflow filters and due date sorts', function (): void {
    $toolbar = resolve(FetchDossierIndexQuery::class)->toolbarMetadata();

    expect($toolbar['defaults']['sort'])->toBe('-updated_at')
        ->and(collect($toolbar['filters'])->pluck('key')->all())->toBe(['q', 'status', 'client', 'queue'])
        ->and(collect($toolbar['filters'])->firstWhere('key', 'status')['options'] ?? [])
        ->toHaveCount(4)
        ->and(collect($toolbar['filters'])->firstWhere('key', 'queue')['options'] ?? [])
        ->toHaveCount(3)
        ->and(collect($toolbar['sorts'])->pluck('key')->all())
        ->toContain('-updated_at', 'title', 'status', 'due_date');
});

test('dossiers index filters by status and search', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();

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
        ->get(workspaceRoute('workspaces.dossiers.index', $tenant).'?filter[status]=draft')
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('dossiers/index')
            ->has('dossiers.data', 1)
            ->where('dossiers.data.0.title', 'Payroll 2025')
            ->where('dossiers.data.0.status', 'draft')
            ->has('indexQuery'));

    $this->actingAs($owner)
        ->get(workspaceRoute('workspaces.dossiers.index', $tenant).'?filter[q]=Payroll')
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->has('dossiers.data', 1)
            ->where('dossiers.data.0.reference', 'PAY-001'));
});

test('dossiers index sorts by title ascending', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();
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
        ->get(workspaceRoute('workspaces.dossiers.index', $tenant).'?sort=title')
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('dossiers.data.0.title', 'Alpha dossier')
            ->where('dossiers.data.1.title', 'Zebra dossier'));
});

test('dossiers index rejects invalid sort', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();

    $this->actingAs($owner)
        ->get(workspaceRoute('workspaces.dossiers.index', $tenant).'?sort=password')
        ->assertStatus(400);
});
