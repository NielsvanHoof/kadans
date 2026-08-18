<?php

declare(strict_types=1);

use App\Clients\Models\Client;
use App\Dossiers\Enums\DocumentRequestStatus;
use App\Dossiers\Models\DocumentRequest;
use App\Dossiers\Models\Dossier;
use App\Questionnaires\Enums\QuestionnaireItemType;

/*
|--------------------------------------------------------------------------
| Inertia prop contract
|--------------------------------------------------------------------------
|
| Locks the *shape* of the props each page sends — key paths and scalar types,
| not values. The DTO layer is what the frontend types mirror, so a silent
| rename or type change here is a frontend break that no PHP test would catch.
|
| Regenerate deliberately (only when a shape change is intended):
|   REBUILD_INERTIA_CONTRACT=1 vendor/bin/sail artisan test --filter='prop contract'
|
*/

/**
 * Reduce a payload to key paths → type names, so values may vary freely.
 *
 * @param  array<array-key, mixed>  $payload
 * @return array<string, string>
 */
function propShape(array $payload, string $prefix = ''): array
{
    $shape = [];

    foreach ($payload as $key => $value) {
        // Collapse lists to their first element: [0] stands for every entry.
        $path = $prefix === '' ? (string) $key : "{$prefix}.{$key}";

        if (is_array($value)) {
            $isList = array_is_list($value);

            if ($isList && $value === []) {
                $shape[$path] = 'list<empty>';

                continue;
            }

            if ($isList) {
                $shape += propShape([0 => $value[0]], $path);

                continue;
            }

            $shape += propShape($value, $path);

            continue;
        }

        $shape[$path] = get_debug_type($value);
    }

    ksort($shape);

    return $shape;
}

/**
 * @return array<string, string>
 */
function pageShape(string $url): array
{
    /** @var array{props: array<string, mixed>} $page */
    $page = test()->get($url)->assertOk()->viewData('page');

    // Shared props are covered by SharedInertiaDataTest; keep this to page props.
    $shared = ['name', 'locale', 'available_locales', 'timezone', 'translations', 'auth',
        'workspaces', 'current_firm', 'permissions', 'support_session',
        'notifications', 'sidebar_open', 'errors', 'flash'];

    return propShape(array_diff_key($page['props'], array_flip($shared)));
}

test('dossier page props keep their contract', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();

    $client = Client::factory()->create(['tenant_id' => $tenant->id]);
    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
        'reference' => 'REF-1',
        'due_date' => now()->addWeek(),
        'reminder_interval_days' => 7,
    ]);

    // Every nullable field is pinned: the factory randomises some of them, and a
    // null would be recorded as its own "type", making the fixture flap.
    DocumentRequest::factory()->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $dossier->id,
        'type' => QuestionnaireItemType::Text,
        'status' => DocumentRequestStatus::Submitted,
        'title' => 'Proof of address',
        'instructions' => 'Upload a recent utility bill.',
        'answer_text' => 'An answer',
        'answered_at' => now(),
    ]);

    $this->actingAs($owner);

    $shapes = [
        'dossiers/index' => pageShape(workspaceRoute('workspaces.dossiers.index', $tenant)),
        'dossiers/show' => pageShape(workspaceRoute('workspaces.dossiers.show', $tenant, ['dossier' => $dossier])),
        'dossiers/review' => pageShape(workspaceRoute('workspaces.dossiers.review', $tenant, ['dossier' => $dossier])),
        'dossiers/builder' => pageShape(workspaceRoute('workspaces.dossiers.builder', $tenant, ['dossier' => $dossier])),
        'dossiers/archived' => pageShape(workspaceRoute('workspaces.dossiers.archived', $tenant)),
        'clients/index' => pageShape(workspaceRoute('workspaces.clients.index', $tenant)),
    ];

    $fixture = __DIR__.'/../../fixtures/inertia-prop-contract.json';

    if (env('REBUILD_INERTIA_CONTRACT') === '1') {
        file_put_contents(
            $fixture,
            json_encode($shapes, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n",
        );
    }

    expect(file_exists($fixture))->toBeTrue('Prop contract fixture is missing.');

    /** @var array<string, array<string, string>> $expected */
    $expected = json_decode((string) file_get_contents($fixture), true, flags: JSON_THROW_ON_ERROR);

    foreach ($expected as $page => $expectedShape) {
        expect($shapes[$page] ?? [])->toBe(
            $expectedShape,
            "Inertia prop shape changed for [{$page}]. If intended, rebuild the fixture.",
        );
    }
});
