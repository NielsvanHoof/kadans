<?php

declare(strict_types=1);

use App\Accounts\Models\User;
use App\Clients\Models\Client;
use App\Dossiers\Enums\DocumentRequestStatus;
use App\Dossiers\Enums\DossierStatus;
use App\Dossiers\Models\DocumentRequest;
use App\Dossiers\Models\Dossier;
use App\Questionnaires\Enums\QuestionnaireItemType;
use App\Tenancy\Enums\Role;
use Database\Seeders\SystemQuestionnaireTemplateSeeder;
use Illuminate\Support\Str;

/**
 * @return array{owner: User, tenant: mixed, dossier: Dossier}
 */
function createSyncContext(): array
{
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();

    $client = Client::factory()->create(['tenant_id' => $tenant->id]);
    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
        'title' => 'Payroll sync',
        'status' => DossierStatus::Draft,
    ]);

    grantDossierBuilderLock($dossier, $owner);

    return [
        'owner' => $owner,
        'tenant' => $tenant,
        'dossier' => $dossier->fresh(),
    ];
}

test('sync creates updates deletes and reorders document requests in one request', function (): void {
    [
        'owner' => $owner,
        'tenant' => $tenant,
        'dossier' => $dossier,
    ] = createSyncContext();

    $keep = DocumentRequest::factory()->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $dossier->id,
        'title' => 'Keep me',
        'type' => QuestionnaireItemType::Text,
        'sort_order' => 0,
    ]);
    $remove = DocumentRequest::factory()->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $dossier->id,
        'title' => 'Remove me',
        'type' => QuestionnaireItemType::File,
        'sort_order' => 1,
    ]);

    $newFieldKey = (string) Str::ulid();

    $this->actingAs($owner)
        ->putJson(workspaceRoute('workspaces.dossiers.document-requests.sync', $tenant, [
            'dossier' => $dossier,
        ]), [
            'items' => [
                [
                    'id' => null,
                    'field_key' => $newFieldKey,
                    'type' => QuestionnaireItemType::Boolean->value,
                    'title' => 'Brand new',
                    'instructions' => null,
                    'configuration' => ['is_required' => true],
                ],
                [
                    'id' => $keep->id,
                    'field_key' => $keep->field_key,
                    'type' => QuestionnaireItemType::Text->value,
                    'title' => 'Keep me renamed',
                    'instructions' => 'Updated',
                    'configuration' => ['is_required' => false],
                ],
            ],
        ])
        ->assertOk()
        ->assertJsonPath('document_requests.0.title', 'Brand new')
        ->assertJsonPath('document_requests.0.field_key', $newFieldKey)
        ->assertJsonPath('document_requests.1.id', $keep->id)
        ->assertJsonPath('document_requests.1.title', 'Keep me renamed')
        ->assertJsonPath('flash.toast.type', 'success')
        ->assertJsonPath('flash.toast.message', __('Form saved.'))
        ->assertJsonCount(2, 'document_requests');

    expect(DocumentRequest::query()->whereKey($remove->id)->exists())->toBeFalse()
        ->and(DocumentRequest::query()->whereBelongsTo($dossier)->orderBy('sort_order')->pluck('title')->all())
        ->toBe(['Brand new', 'Keep me renamed']);
});

test('sync can clear the canvas with an empty items array', function (): void {
    [
        'owner' => $owner,
        'tenant' => $tenant,
        'dossier' => $dossier,
    ] = createSyncContext();

    DocumentRequest::factory()->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $dossier->id,
        'status' => DocumentRequestStatus::Pending,
    ]);

    $this->actingAs($owner)
        ->putJson(workspaceRoute('workspaces.dossiers.document-requests.sync', $tenant, [
            'dossier' => $dossier,
        ]), [
            'items' => [],
        ])
        ->assertOk()
        ->assertJsonPath('document_requests', []);

    expect(DocumentRequest::query()->whereBelongsTo($dossier)->count())->toBe(0);
});

test('sync rejects visibility rules that point at a later field in the payload', function (): void {
    [
        'owner' => $owner,
        'tenant' => $tenant,
        'dossier' => $dossier,
    ] = createSyncContext();

    $earlierKey = (string) Str::ulid();
    $laterKey = (string) Str::ulid();

    $this->actingAs($owner)
        ->putJson(workspaceRoute('workspaces.dossiers.document-requests.sync', $tenant, [
            'dossier' => $dossier,
        ]), [
            'items' => [
                [
                    'id' => null,
                    'field_key' => $earlierKey,
                    'type' => QuestionnaireItemType::Text->value,
                    'title' => 'Dependent',
                    'instructions' => null,
                    'configuration' => [
                        'is_required' => true,
                        'visibility' => [
                            'source_field_key' => $laterKey,
                            'option_ids' => ['yes'],
                        ],
                    ],
                ],
                [
                    'id' => null,
                    'field_key' => $laterKey,
                    'type' => QuestionnaireItemType::Boolean->value,
                    'title' => 'Source',
                    'instructions' => null,
                    'configuration' => ['is_required' => true],
                ],
            ],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['items.0.configuration.visibility.source_field_key']);
});

test('read only members cannot sync the builder draft', function (): void {
    [
        'tenant' => $tenant,
        'dossier' => $dossier,
    ] = createSyncContext();

    $reader = workspaceMember($tenant, Role::ReadOnly);

    $this->actingAs($reader)
        ->putJson(workspaceRoute('workspaces.dossiers.document-requests.sync', $tenant, [
            'dossier' => $dossier,
        ]), [
            'items' => [],
        ])
        ->assertForbidden();
});

test('builder page embeds template items when templates exist', function (): void {
    [
        'owner' => $owner,
        'tenant' => $tenant,
        'dossier' => $dossier,
    ] = createSyncContext();

    $this->seed(SystemQuestionnaireTemplateSeeder::class);

    $this->actingAs($owner)
        ->get(workspaceRoute('workspaces.dossiers.builder', $tenant, ['dossier' => $dossier]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('workspaces/dossiers/builder')
            ->has('templates.0.items')
            ->where('templates.0.items.0.type', fn ($type): bool => is_string($type)));
});
