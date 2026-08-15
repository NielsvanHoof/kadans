<?php

declare(strict_types=1);

use App\Accounts\Models\User;
use App\Clients\Models\Client;
use App\Dossiers\Enums\DocumentRequestPermission;
use App\Dossiers\Enums\DossierStatus;
use App\Dossiers\Http\Requests\Builder\SyncDocumentRequestsRequest;
use App\Dossiers\Models\DocumentRequest;
use App\Dossiers\Models\Dossier;
use App\Questionnaires\Enums\QuestionnaireItemType;
use App\Tenancy\Enums\Role;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Models\TenantRole;
use Illuminate\Support\Str;

covers(SyncDocumentRequestsRequest::class);

/**
 * A draft dossier holding one document request, plus an adviser who holds the
 * builder lock. Each test then narrows the adviser's role to isolate one ability.
 *
 * @return array{tenant: Tenant, dossier: Dossier, adviser: User, documentRequest: DocumentRequest}
 */
function createBuilderSyncContext(): array
{
    ['tenant' => $tenant] = provisionWorkspace();

    $client = Client::factory()->create(['tenant_id' => $tenant->id]);
    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
        'status' => DossierStatus::Draft,
    ]);

    $documentRequest = DocumentRequest::factory()->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $dossier->id,
        'field_key' => (string) Str::ulid(),
        'title' => 'Original title',
        'sort_order' => 0,
    ]);

    $adviser = workspaceMember($tenant, Role::Adviser);
    grantDossierBuilderLock($dossier, $adviser);

    return [
        'tenant' => $tenant,
        'dossier' => $dossier,
        'adviser' => $adviser,
        'documentRequest' => $documentRequest,
    ];
}

/**
 * Drop one permission from the seeded Adviser role, the way a firm-authored
 * custom role would arrive without it.
 */
function revokeAdviserPermission(Tenant $tenant, DocumentRequestPermission $permission): void
{
    TenantRole::query()
        ->where('name', Role::Adviser->value)
        ->where('tenant_id', $tenant->id)
        ->firstOrFail()
        ->revokePermissionTo($permission->value);
}

test('the default adviser role saves a canvas that removes a row', function (): void {
    [
        'tenant' => $tenant,
        'dossier' => $dossier,
        'adviser' => $adviser,
        'documentRequest' => $documentRequest,
    ] = createBuilderSyncContext();

    $this->actingAs($adviser)
        ->putJson(workspaceRoute('workspaces.dossiers.document-requests.sync', $tenant, [
            'dossier' => $dossier,
        ]), ['items' => []])
        ->assertOk();

    expect(DocumentRequest::query()->whereKey($documentRequest->id)->exists())->toBeFalse();
});

test('omitting a row from the canvas requires the delete permission', function (): void {
    [
        'tenant' => $tenant,
        'dossier' => $dossier,
        'adviser' => $adviser,
        'documentRequest' => $documentRequest,
    ] = createBuilderSyncContext();

    revokeAdviserPermission($tenant, DocumentRequestPermission::Delete);

    $this->actingAs($adviser)
        ->putJson(workspaceRoute('workspaces.dossiers.document-requests.sync', $tenant, [
            'dossier' => $dossier,
        ]), ['items' => []])
        ->assertForbidden();

    expect(DocumentRequest::query()->whereKey($documentRequest->id)->exists())->toBeTrue();
});

test('retaining a row in the canvas requires the update permission', function (): void {
    [
        'tenant' => $tenant,
        'dossier' => $dossier,
        'adviser' => $adviser,
        'documentRequest' => $documentRequest,
    ] = createBuilderSyncContext();

    revokeAdviserPermission($tenant, DocumentRequestPermission::Update);

    $this->actingAs($adviser)
        ->putJson(workspaceRoute('workspaces.dossiers.document-requests.sync', $tenant, [
            'dossier' => $dossier,
        ]), [
            'items' => [
                [
                    'id' => $documentRequest->id,
                    'field_key' => $documentRequest->field_key,
                    'type' => QuestionnaireItemType::Text->value,
                    'title' => 'Rewritten title',
                ],
            ],
        ])
        ->assertForbidden();

    expect($documentRequest->fresh()?->title)->toBe('Original title');
});

test('adding a row to an empty canvas needs only the create permission', function (): void {
    ['tenant' => $tenant] = provisionWorkspace();

    $client = Client::factory()->create(['tenant_id' => $tenant->id]);
    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
        'status' => DossierStatus::Draft,
    ]);

    $adviser = workspaceMember($tenant, Role::Adviser);
    grantDossierBuilderLock($dossier, $adviser);

    revokeAdviserPermission($tenant, DocumentRequestPermission::Update);
    revokeAdviserPermission($tenant, DocumentRequestPermission::Delete);

    $this->actingAs($adviser)
        ->putJson(workspaceRoute('workspaces.dossiers.document-requests.sync', $tenant, [
            'dossier' => $dossier,
        ]), [
            'items' => [
                [
                    'field_key' => (string) Str::ulid(),
                    'type' => QuestionnaireItemType::Text->value,
                    'title' => 'Brand new question',
                ],
            ],
        ])
        ->assertOk();

    expect(DocumentRequest::query()->whereBelongsTo($dossier)->count())->toBe(1);
});

test('a read only member cannot sync the canvas at all', function (): void {
    [
        'tenant' => $tenant,
        'dossier' => $dossier,
        'documentRequest' => $documentRequest,
    ] = createBuilderSyncContext();

    $readOnly = workspaceMember($tenant, Role::ReadOnly);
    grantDossierBuilderLock($dossier, $readOnly);

    $this->actingAs($readOnly)
        ->putJson(workspaceRoute('workspaces.dossiers.document-requests.sync', $tenant, [
            'dossier' => $dossier,
        ]), ['items' => []])
        ->assertForbidden();

    expect(DocumentRequest::query()->whereKey($documentRequest->id)->exists())->toBeTrue();
});
