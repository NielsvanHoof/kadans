<?php

declare(strict_types=1);

use App\Clients\Models\Client;
use App\Clients\Queries\FetchClientIndexQuery;
use App\Dossiers\Models\Dossier;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\Request;
use Inertia\Testing\AssertableInertia as Assert;

covers(FetchClientIndexQuery::class);

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
});

test('fetch client index maps rows and exposes toolbar metadata', function (): void {
    ['tenant' => $tenant] = provisionWorkspace();

    $client = Client::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Jane Client',
        'email' => 'jane@example.com',
    ]);
    Dossier::factory()->count(2)->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
    ]);

    // Bind an empty request so Spatie Query Builder can read filters/sorts.
    app()->instance('request', Request::create('/clients', 'GET'));

    $paginator = resolve(FetchClientIndexQuery::class)->handle();
    $toolbar = resolve(FetchClientIndexQuery::class)->toolbarMetadata();

    expect($paginator->total())->toBe(1)
        ->and($paginator->items()[0])->toMatchArray([
            'id' => $client->id,
            'name' => 'Jane Client',
            'email' => 'jane@example.com',
            'dossiers_count' => 2,
        ])
        ->and($toolbar['defaults']['sort'])->toBe('name')
        ->and($toolbar['filters'][0]['key'])->toBe('q')
        ->and(collect($toolbar['sorts'])->pluck('key')->all())->toContain('name', '-name');
});

test('clients index paginates results and exposes indexQuery config', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();

    // Create more than one page (default per_page = 15).
    Client::factory()->count(16)->create(['tenant_id' => $tenant->id]);

    $this->actingAs($owner)
        ->get(workspaceRoute('workspaces.clients.index', $tenant))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('clients/index')
            ->has('clients.data', 15)
            ->where('clients.total', 16)
            ->where('clients.current_page', 1)
            ->where('clients.last_page', 2)
            ->has('indexQuery.filters')
            ->has('indexQuery.sorts')
            ->where('indexQuery.defaults.sort', 'name')
            ->where('filters', [])
            ->where('sort', null));

    $this->actingAs($owner)
        ->get(workspaceRoute('workspaces.clients.index', $tenant).'?page=2')
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->has('clients.data', 1)
            ->where('clients.current_page', 2));
});

test('clients index filters by search query across name and email', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();

    Client::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Acme Corp',
        'email' => 'info@acme.test',
    ]);
    Client::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Other Client',
        'email' => 'other@example.com',
    ]);
    Client::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Partner BV',
        'email' => 'hello@acme-partner.test',
    ]);

    $this->actingAs($owner)
        ->get(workspaceRoute('workspaces.clients.index', $tenant).'?filter[q]=acme')
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->has('clients.data', 2)
            ->where('clients.total', 2)
            ->where('filters.q', 'acme')
            ->where('clients.data', fn ($clients) => collect($clients)->every(
                fn (array $client): bool => str_contains(mb_strtolower($client['name'].$client['email']), 'acme'),
            )));
});

test('clients index inertia page url keeps readable filter brackets', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();

    Client::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Acme Corp',
        'email' => 'info@acme.test',
    ]);

    $this->actingAs($owner)
        ->get(workspaceRoute('workspaces.clients.index', $tenant).'?filter[q]=acme&sort=-name')
        ->assertOk()
        ->assertInertia(function (Assert $page): void {
            $url = $page->toArray()['url'];

            // fullUrl() encodes [ ] as %5B/%5D; urlResolver must keep Spatie filters readable.
            expect($url)
                ->toContain('filter[q]=acme')
                ->toContain('sort=-name')
                ->not->toContain('%5B')
                ->not->toContain('%5D');

            $page
                ->where('filters.q', 'acme')
                ->where('sort', '-name');
        });
});

test('clients index sorts by name descending', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();

    Client::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Alpha Client',
        'email' => 'alpha@example.com',
    ]);
    Client::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Zulu Client',
        'email' => 'zulu@example.com',
    ]);

    $this->actingAs($owner)
        ->get(workspaceRoute('workspaces.clients.index', $tenant).'?sort=-name')
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('sort', '-name')
            ->where('clients.data.0.name', 'Zulu Client')
            ->where('clients.data.1.name', 'Alpha Client'));
});

test('clients index rejects invalid filters', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();

    $this->actingAs($owner)
        ->get(workspaceRoute('workspaces.clients.index', $tenant).'?filter[unknown]=x')
        ->assertBadRequest();
});

test('clients index does not leak another tenants clients', function (): void {
    ['owner' => $ownerA, 'tenant' => $tenantA] = provisionWorkspace('Tenant A');
    ['tenant' => $tenantB] = provisionWorkspace('Tenant B');

    Client::factory()->create([
        'tenant_id' => $tenantB->id,
        'name' => 'Foreign Client',
        'email' => 'foreign@example.com',
    ]);

    actingInWorkspace($tenantA);
    Client::factory()->create([
        'tenant_id' => $tenantA->id,
        'name' => 'Own Client',
        'email' => 'own@example.com',
    ]);

    $this->actingAs($ownerA)
        ->get(workspaceRoute('workspaces.clients.index', $tenantA).'?filter[q]=Client')
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->has('clients.data', 1)
            ->where('clients.data.0.name', 'Own Client'));
});
