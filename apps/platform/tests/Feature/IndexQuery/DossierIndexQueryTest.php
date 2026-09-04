<?php

declare(strict_types=1);

use App\Clients\Models\Client;
use App\Dossiers\Enums\DossierStatus;
use App\Dossiers\Models\Dossier;
use App\Dossiers\Queries\FetchDossierIndexQuery;
use App\Tenancy\Enums\Role;
use Database\Seeders\RolesAndPermissionsSeeder;
use Inertia\Testing\AssertableInertia as Assert;

covers(FetchDossierIndexQuery::class);

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
});

test('dossier index toolbar metadata includes workflow filters and due date sorts', function (): void {
    $toolbar = resolve(FetchDossierIndexQuery::class)->toolbarMetadata();

    expect($toolbar['defaults']['sort'])->toBe('-updated_at')
        ->and(collect($toolbar['filters'])->pluck('key')->all())->toBe(['q', 'status', 'client', 'queue', 'responsible'])
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
            ->component('workspaces/dossiers/index')
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

test('dossiers index filters by responsible staff member', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();
    $colleague = workspaceMember($tenant, Role::Adviser);
    $client = Client::factory()->create(['tenant_id' => $tenant->id]);

    Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
        'title' => 'Mine',
        'responsible_user_id' => $owner->id,
    ]);
    Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
        'title' => 'Theirs',
        'responsible_user_id' => $colleague->id,
    ]);
    Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
        'title' => 'Unassigned',
        'responsible_user_id' => null,
    ]);

    $this->actingAs($owner)
        ->get(workspaceRoute('workspaces.dossiers.index', $tenant)."?filter[responsible]={$owner->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->has('dossiers.data', 1)
            ->where('dossiers.data.0.title', 'Mine'));
});

test('dossier toolbar lists active workspace staff as responsible options', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();
    $colleague = workspaceMember($tenant, Role::Adviser);

    actingInWorkspace($tenant);

    $options = collect(
        resolve(FetchDossierIndexQuery::class)->toolbarMetadata()['filters']
    )->firstWhere('key', 'responsible')['options'] ?? [];

    expect(collect($options)->pluck('value')->all())
        ->toContain((string) $owner->id, (string) $colleague->id);
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
        ->assertBadRequest();
});

test('dossiers index surfaces scout search truncation when the key limit is hit', function (): void {
    config(['scout.key_limit' => 2]);

    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();
    $client = Client::factory()->create(['tenant_id' => $tenant->id]);

    foreach (['Alpha UniqueNeedle', 'Beta UniqueNeedle', 'Gamma UniqueNeedle'] as $title) {
        Dossier::factory()->create([
            'tenant_id' => $tenant->id,
            'client_id' => $client->id,
            'title' => $title,
        ]);
    }

    $this->actingAs($owner)
        ->get(workspaceRoute('workspaces.dossiers.index', $tenant).'?filter[q]=UniqueNeedle')
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->has('dossiers.data', 2)
            ->where('indexQuery.search_truncated', true));
});

test('dossiers index searches on the period label and returns it in the row', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();
    $client = Client::factory()->create(['tenant_id' => $tenant->id]);

    Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
        'title' => 'Annual accounts',
        'reference' => 'ANN-2025',
        'period_year' => 2025,
        'period_label' => 'Boekjaar 2025',
    ]);
    Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
        'title' => 'Payroll',
        'reference' => 'PAY-2024',
        'period_year' => 2024,
        'period_label' => 'Boekjaar 2024',
    ]);

    // The period is in toSearchableArray, so a firm can find last year's work
    // by the name it gives the period rather than by title or reference.
    $this->actingAs($owner)
        ->get(workspaceRoute('workspaces.dossiers.index', $tenant).'?filter[q]=Boekjaar+2025')
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->has('dossiers.data', 1)
            ->where('dossiers.data.0.period_label', 'Boekjaar 2025')
            ->where('dossiers.data.0.period_year', 2025));
});
