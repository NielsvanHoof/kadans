<?php

declare(strict_types=1);

use App\Clients\Models\Client;
use App\Clients\Queries\FetchClientIndexQuery;
use App\Dossiers\Models\Dossier;
use Illuminate\Http\Request;

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
