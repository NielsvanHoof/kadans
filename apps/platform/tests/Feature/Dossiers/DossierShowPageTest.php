<?php

declare(strict_types=1);

use App\Accounts\Models\User;
use App\ClientPortal\Models\ClientAccessGrant;
use App\Clients\Models\Client;
use App\Dossiers\Enums\DocumentRequestStatus;
use App\Dossiers\Enums\DossierStatus;
use App\Dossiers\Models\AudioRecording;
use App\Dossiers\Models\DocumentRequest;
use App\Dossiers\Models\Dossier;
use App\Dossiers\Models\DossierExport;
use App\Dossiers\Models\UploadedDocument;
use App\Pipelines\Enums\PipelineType;
use App\Pipelines\Models\Pipeline;
use App\Tenancy\Enums\Role;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * @return array{owner: User, tenant: mixed, dossier: Dossier}
 */
function createDossierShowContext(
    DossierStatus $status,
    bool $withDocumentRequest = true,
): array {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();

    $client = Client::factory()->create(['tenant_id' => $tenant->id]);
    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
        'title' => 'Payroll review '.$status->value,
        'status' => $status,
    ]);

    if ($withDocumentRequest) {
        DocumentRequest::factory()->create([
            'tenant_id' => $tenant->id,
            'dossier_id' => $dossier->id,
            'title' => 'Payslip',
            'type' => 'file',
            'status' => match ($status) {
                DossierStatus::Completed, DossierStatus::InReview => DocumentRequestStatus::Accepted,
                default => DocumentRequestStatus::Pending,
            },
            'sort_order' => 0,
        ]);
    }

    return [
        'owner' => $owner,
        'tenant' => $tenant,
        'dossier' => $dossier,
    ];
}

test('dossier overview page renders props for each workflow status', function (DossierStatus $status): void {
    [
        'owner' => $owner,
        'tenant' => $tenant,
        'dossier' => $dossier,
    ] = createDossierShowContext($status);

    $this->actingAs($owner)
        ->get(workspaceRoute('workspaces.dossiers.show', $tenant, ['dossier' => $dossier]))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('dossiers/show')
            ->where('dossier.id', $dossier->id)
            ->where('dossier.status', $status->value)
            ->has('dossier.document_requests', 0)
            ->has('dossier.review_summary')
            ->has('dossier.access_grants')
            ->has('dossier.exports')
            ->missing('templates')
            ->has('can_manage')
            ->has('can_edit')
            ->has('can_review')
            ->has('can_download')
            ->loadDeferredProps(fn (Assert $reload): Assert => $reload
                ->has('document_requests', 1)));
})->with([
    DossierStatus::Draft,
    DossierStatus::AwaitingClient,
    DossierStatus::InReview,
    DossierStatus::Completed,
]);

test('dossier builder page renders lean builder props', function (): void {
    [
        'owner' => $owner,
        'tenant' => $tenant,
        'dossier' => $dossier,
    ] = createDossierShowContext(DossierStatus::Draft);

    $this->actingAs($owner)
        ->get(workspaceRoute('workspaces.dossiers.builder', $tenant, ['dossier' => $dossier]))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('dossiers/builder')
            ->where('dossier.id', $dossier->id)
            ->where('dossier.title', $dossier->title)
            ->where('dossier.status', DossierStatus::Draft->value)
            ->has('dossier.document_requests', 1)
            ->missing('dossier.access_grants')
            ->missing('dossier.exports')
            ->missing('dossier.review_summary')
            ->has('templates')
            ->where('can_manage', true)
            ->where('builder_lock.is_mine', true)
            ->has('builder_lock.token')
            ->missing('can_edit')
            ->missing('can_review')
            ->missing('can_download'));
});

test('builder mutations redirect back to the builder instead of the overview', function (): void {
    [
        'owner' => $owner,
        'tenant' => $tenant,
        'dossier' => $dossier,
    ] = createDossierShowContext(DossierStatus::Draft);

    $builderUrl = workspaceRoute('workspaces.dossiers.builder', $tenant, ['dossier' => $dossier]);
    $existing = $dossier->documentRequests()->sole();

    grantDossierBuilderLock($dossier, $owner);

    $this->actingAs($owner)
        ->post(workspaceRoute('workspaces.dossiers.document-requests.store', $tenant, [
            'dossier' => $dossier,
        ]), [
            'type' => 'text',
            'title' => 'Added from builder',
        ])
        ->assertRedirect($builderUrl);

    $this->put(workspaceRoute('workspaces.dossiers.document-requests.update', $tenant, [
        'dossier' => $dossier,
        'documentRequest' => $existing,
    ]), [
        'type' => 'file',
        'title' => 'Renamed on builder',
        'instructions' => null,
    ])
        ->assertRedirect($builderUrl);

    $this->delete(workspaceRoute('workspaces.dossiers.document-requests.destroy', $tenant, [
        'dossier' => $dossier,
        'documentRequest' => $existing,
    ]))
        ->assertRedirect($builderUrl);
});

test('builder island mutations return json for the touched document request', function (): void {
    [
        'owner' => $owner,
        'tenant' => $tenant,
        'dossier' => $dossier,
    ] = createDossierShowContext(DossierStatus::Draft);

    $existing = $dossier->documentRequests()->sole();

    grantDossierBuilderLock($dossier, $owner);

    $this->actingAs($owner)
        ->postJson(workspaceRoute('workspaces.dossiers.document-requests.store', $tenant, [
            'dossier' => $dossier,
        ]), [
            'type' => 'text',
            'title' => 'Island create',
            'position' => 0,
        ])
        ->assertCreated()
        ->assertJsonPath('document_request.title', 'Island create')
        ->assertJsonPath('document_request.type', 'text')
        ->assertJsonStructure(['document_request' => [
            'id',
            'field_key',
            'sort_order',
            'is_visible',
            'is_applicable',
        ]]);

    $this->putJson(workspaceRoute('workspaces.dossiers.document-requests.update', $tenant, [
        'dossier' => $dossier,
        'documentRequest' => $existing,
    ]), [
        'type' => 'file',
        'title' => 'Island update',
        'instructions' => 'Upload PDF',
    ])
        ->assertOk()
        ->assertJsonPath('document_request.title', 'Island update')
        ->assertJsonPath('document_request.type', 'file');

    $this->deleteJson(workspaceRoute('workspaces.dossiers.document-requests.destroy', $tenant, [
        'dossier' => $dossier,
        'documentRequest' => $existing,
    ]))
        ->assertNoContent();

    expect(DocumentRequest::query()->whereKey($existing->id)->exists())->toBeFalse();
});

test('builder island reorder still returns no content for json clients', function (): void {
    [
        'owner' => $owner,
        'tenant' => $tenant,
        'dossier' => $dossier,
    ] = createDossierShowContext(DossierStatus::Draft);

    $first = $dossier->documentRequests()->sole();
    $second = DocumentRequest::factory()->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $dossier->id,
        'sort_order' => 1,
    ]);

    grantDossierBuilderLock($dossier, $owner);

    $this->actingAs($owner)
        ->postJson(workspaceRoute('workspaces.dossiers.document-requests.reorder', $tenant, [
            'dossier' => $dossier,
        ]), [
            'document_request_ids' => [$second->id, $first->id],
        ])
        ->assertNoContent();

    expect(DocumentRequest::query()
        ->whereBelongsTo($dossier)
        ->oldest('sort_order')
        ->pluck('id')
        ->all())->toBe([$second->id, $first->id]);
});

test('dossier review page renders lean review props', function (): void {
    [
        'owner' => $owner,
        'tenant' => $tenant,
        'dossier' => $dossier,
    ] = createDossierShowContext(DossierStatus::InReview);

    $this->actingAs($owner)
        ->get(workspaceRoute('workspaces.dossiers.review', $tenant, ['dossier' => $dossier]))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('dossiers/review')
            ->where('dossier.id', $dossier->id)
            ->has('dossier.client')
            ->has('dossier.document_requests', 1)
            ->has('dossier.review_summary')
            ->has('dossier.ready_to_complete')
            ->missing('dossier.access_grants')
            ->missing('dossier.exports')
            ->missing('templates')
            ->where('can_review', true)
            ->has('can_download')
            ->has('can_manage')
            ->has('can_edit'));
});

test('empty dossier overview exposes zero form items for invite gating', function (): void {
    [
        'owner' => $owner,
        'tenant' => $tenant,
        'dossier' => $dossier,
    ] = createDossierShowContext(DossierStatus::Draft, withDocumentRequest: false);

    $this->actingAs($owner)
        ->get(workspaceRoute('workspaces.dossiers.show', $tenant, ['dossier' => $dossier]))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('dossiers/show')
            ->has('dossier.document_requests', 0)
            ->where('dossier.review_summary.total', 0)
            ->where('dossier.ready_to_complete', false)
            ->missing('templates')
            ->loadDeferredProps(fn (Assert $reload): Assert => $reload
                ->has('document_requests', 0)));
});

test('read-only member can view overview builder and review with restricted action flags', function (): void {
    [
        'tenant' => $tenant,
        'dossier' => $dossier,
    ] = createDossierShowContext(DossierStatus::AwaitingClient);

    $reader = workspaceMember($tenant, Role::ReadOnly);

    $this->actingAs($reader)
        ->get(workspaceRoute('workspaces.dossiers.show', $tenant, ['dossier' => $dossier]))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('dossiers/show')
            ->where('can_manage', false)
            ->where('can_edit', false)
            ->where('can_review', false));

    $this->actingAs($reader)
        ->get(workspaceRoute('workspaces.dossiers.builder', $tenant, ['dossier' => $dossier]))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('dossiers/builder')
            ->where('can_manage', false)
            ->where('builder_lock.is_mine', false)
            ->missing('can_edit')
            ->missing('can_review'));

    $this->actingAs($reader)
        ->get(workspaceRoute('workspaces.dossiers.review', $tenant, ['dossier' => $dossier]))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('dossiers/review')
            ->where('can_manage', false)
            ->where('can_edit', false)
            ->where('can_review', false));
});

test('reviewer can view dossier surfaces with review permissions only', function (): void {
    [
        'tenant' => $tenant,
        'dossier' => $dossier,
    ] = createDossierShowContext(DossierStatus::InReview);

    $reviewer = workspaceMember($tenant, Role::Reviewer);

    $this->actingAs($reviewer)
        ->get(workspaceRoute('workspaces.dossiers.review', $tenant, ['dossier' => $dossier]))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('dossiers/review')
            ->where('can_manage', false)
            ->where('can_edit', false)
            ->where('can_review', true)
            ->has('can_download')
            ->missing('templates'));
});

test('dossier overview with multiple audio recordings resolves can_delete without lazy loading', function (): void {
    [
        'owner' => $owner,
        'tenant' => $tenant,
        'dossier' => $dossier,
    ] = createDossierShowContext(DossierStatus::InReview);

    // Two+ hydrated models enable preventsLazyLoading; automatic eager loading
    // must still allow can_delete authorization against dossier.
    AudioRecording::factory()->count(2)->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $dossier->id,
    ]);

    $this->actingAs($owner)
        ->get(workspaceRoute('workspaces.dossiers.show', $tenant, ['dossier' => $dossier]))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('dossiers/show')
            ->missing('audio_recordings')
            ->loadDeferredProps(fn (Assert $reload): Assert => $reload
                ->has('audio_recordings', 2)
                ->where('audio_recordings.0.can_delete', true)
                ->where('audio_recordings.1.can_delete', true)));
});

test('dossier overview exposes synchronous export and recording counts for the tab strip', function (): void {
    [
        'owner' => $owner,
        'tenant' => $tenant,
        'dossier' => $dossier,
    ] = createDossierShowContext(DossierStatus::InReview);

    AudioRecording::factory()->count(2)->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $dossier->id,
    ]);

    DossierExport::factory()->count(3)->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $dossier->id,
    ]);

    // The rows themselves stay deferred; only the totals are needed up front so
    // the workbench tab strip cannot change shape after first paint.
    $this->actingAs($owner)
        ->get(workspaceRoute('workspaces.dossiers.show', $tenant, ['dossier' => $dossier]))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('dossiers/show')
            ->where('dossier.audio_recordings_count', 2)
            ->where('dossier.exports_count', 3)
            ->where('dossier.audio_recordings', [])
            ->where('dossier.exports', [])
            ->missing('audio_recordings')
            ->missing('exports'));
});

test('dossier overview counts are zero when nothing has been exported or recorded', function (): void {
    [
        'owner' => $owner,
        'tenant' => $tenant,
        'dossier' => $dossier,
    ] = createDossierShowContext(DossierStatus::AwaitingClient);

    $this->actingAs($owner)
        ->get(workspaceRoute('workspaces.dossiers.show', $tenant, ['dossier' => $dossier]))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('dossiers/show')
            ->where('dossier.audio_recordings_count', 0)
            ->where('dossier.exports_count', 0));
});

test('dossier overview signs the days remaining on the due date', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();

    $client = Client::factory()->create(['tenant_id' => $tenant->id]);

    // Signed on the server so the browser never redoes calendar-day maths
    // across timezones — same contract as the dashboard agenda rows.
    $cases = [
        'future' => [now()->addDays(12)->toDateString(), 12],
        'today' => [today()->toDateString(), 0],
        'past' => [now()->subDays(9)->toDateString(), -9],
        'none' => [null, null],
    ];

    foreach ($cases as $label => [$dueDate, $expected]) {
        $dossier = Dossier::factory()->create([
            'tenant_id' => $tenant->id,
            'client_id' => $client->id,
            'title' => 'Jaarrekening '.$label,
            'status' => DossierStatus::AwaitingClient,
            'due_date' => $dueDate,
        ]);

        $this->actingAs($owner)
            ->get(workspaceRoute('workspaces.dossiers.show', $tenant, ['dossier' => $dossier]))
            ->assertOk()
            ->assertInertia(fn (Assert $page): Assert => $page
                ->component('dossiers/show')
                ->where('dossier.days_remaining', $expected));
    }
});

test('dossier overview serializes only the latest pipeline per uploaded document', function (): void {
    [
        'owner' => $owner,
        'tenant' => $tenant,
        'dossier' => $dossier,
    ] = createDossierShowContext(DossierStatus::InReview);

    $documentRequest = $dossier->documentRequests()->sole();
    $uploadedDocument = UploadedDocument::factory()->create([
        'tenant_id' => $tenant->id,
        'document_request_id' => $documentRequest->id,
    ]);

    Pipeline::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => PipelineType::OcrDocument,
        'pipelineable_type' => $uploadedDocument->getMorphClass(),
        'pipelineable_id' => $uploadedDocument->id,
    ]);
    $latestPipeline = Pipeline::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => PipelineType::OcrDocument,
        'pipelineable_type' => $uploadedDocument->getMorphClass(),
        'pipelineable_id' => $uploadedDocument->id,
    ]);

    $this->actingAs($owner)
        ->get(workspaceRoute('workspaces.dossiers.show', $tenant, ['dossier' => $dossier]))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('dossiers/show')
            ->loadDeferredProps(fn (Assert $reload): Assert => $reload
                ->where('document_requests.0.uploaded_documents.0.pipeline.id', $latestPipeline->id)));
});

test('dossier overview keeps every valid grant and bounds expired grant history', function (): void {
    [
        'owner' => $owner,
        'tenant' => $tenant,
        'dossier' => $dossier,
    ] = createDossierShowContext(DossierStatus::AwaitingClient);

    foreach (range(1, 25) as $daysAgo) {
        ClientAccessGrant::factory()->create([
            'tenant_id' => $tenant->id,
            'dossier_id' => $dossier->id,
            'created_by' => $owner->id,
            'expires_at' => now()->subDays($daysAgo),
        ]);
    }

    $validGrant = ClientAccessGrant::factory()->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $dossier->id,
        'created_by' => $owner->id,
        'expires_at' => now()->addDays(7),
    ]);

    $this->actingAs($owner)
        ->get(workspaceRoute('workspaces.dossiers.show', $tenant, ['dossier' => $dossier]))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('dossiers/show')
            ->has('dossier.access_grants', 21)
            ->where('dossier.access_grants.0.id', $validGrant->id)
            ->where('dossier.access_grants.0.is_valid', true));
});
