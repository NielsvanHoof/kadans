<?php

declare(strict_types=1);

use App\Accounts\Models\User;
use App\Audit\Enums\AuditEvent;
use App\Audit\Models\Activity;
use App\ClientPortal\Models\ClientAccessGrant;
use App\Clients\Models\Client;
use App\Dossiers\Enums\DocumentRequestStatus;
use App\Dossiers\Enums\DossierStatus;
use App\Dossiers\Models\DocumentRequest;
use App\Dossiers\Models\Dossier;
use App\Dossiers\Models\UploadedDocument;
use App\Questionnaires\Enums\QuestionnaireItemType;
use App\Tenancy\Actions\ProvisionTenantAction;
use App\Tenancy\Enums\Role;
use App\Tenancy\Enums\TenantMembershipStatus;
use App\Tenancy\Models\TenantMembership;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
});

test('staff can create a client, dossier, and document request', function (): void {
    $owner = User::factory()->create();
    $tenant = resolve(ProvisionTenantAction::class)->handle('Acme Accountants', $owner);

    $this->actingAs($owner)
        ->withSession(['active_tenant_id' => $tenant->id])
        ->get(workspaceRoute('workspaces.clients.index', $tenant))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('clients/index')
            ->has('clients.data', 0)
            ->where('clients.total', 0)
            ->has('indexQuery'));

    $this->post(workspaceRoute('workspaces.clients.store', $tenant), [
        'name' => 'Jane Client',
        'email' => 'jane@example.com',
    ])->assertRedirect(workspaceRoute('workspaces.dossiers.create', $tenant));

    $tenant->makeCurrent();
    $client = Client::query()->sole();

    $this->post(workspaceRoute('workspaces.dossiers.store', $tenant), [
        'client_id' => $client->id,
        'title' => '2025 Payroll dossier',
        'reference' => 'PAY-2025-001',
    ])->assertRedirect();

    $dossier = Dossier::query()->sole();
    grantDossierBuilderLock($dossier, $owner);

    $this->post(workspaceRoute('workspaces.dossiers.document-requests.store', $tenant, [
        'dossier' => $dossier,
    ]), [
        'type' => 'file',
        'title' => 'Payslip January 2025',
        'instructions' => 'Upload the original PDF.',
    ])->assertRedirect(workspaceRoute('workspaces.dossiers.builder', $tenant, ['dossier' => $dossier]));

    $this->get(workspaceRoute('workspaces.dossiers.show', $tenant, ['dossier' => $dossier]))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('dossiers/show')
            ->has('dossier.document_requests', 1)
            ->where('dossier.status', 'draft'));

    expect(DocumentRequest::query()->where('dossier_id', $dossier->id)->first())
        ->not->toBeNull()
        ->and(Activity::query()
            ->where('event', AuditEvent::DocumentRequestCreated->value)
            ->exists())->toBeTrue();
});

test('staff can insert a document request at a canvas position', function (): void {
    $owner = User::factory()->create();
    $tenant = resolve(ProvisionTenantAction::class)->handle('Acme Accountants', $owner);

    $tenant->makeCurrent();
    $client = Client::factory()->create(['tenant_id' => $tenant->id]);
    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
    ]);

    $first = DocumentRequest::factory()->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $dossier->id,
        'title' => 'First',
        'sort_order' => 0,
    ]);
    $second = DocumentRequest::factory()->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $dossier->id,
        'title' => 'Second',
        'sort_order' => 1,
    ]);

    grantDossierBuilderLock($dossier, $owner);

    $this->actingAs($owner)
        ->withSession(['active_tenant_id' => $tenant->id])
        ->post(workspaceRoute('workspaces.dossiers.document-requests.store', $tenant, [
            'dossier' => $dossier,
        ]), [
            'type' => 'text',
            'title' => 'Inserted middle',
            'position' => 1,
        ])
        ->assertRedirect(workspaceRoute('workspaces.dossiers.builder', $tenant, ['dossier' => $dossier]));

    expect(DocumentRequest::query()
        ->whereBelongsTo($dossier)
        ->oldest('sort_order')
        ->pluck('title')
        ->all())->toBe(['First', 'Inserted middle', 'Second'])
        ->and($first->fresh()->sort_order)
        ->toBe(0)
        ->and($second->fresh()->sort_order)
        ->toBe(2);
});

test('document request position is clamped to the end of the list', function (): void {
    $owner = User::factory()->create();
    $tenant = resolve(ProvisionTenantAction::class)->handle('Acme Accountants', $owner);

    $tenant->makeCurrent();
    $client = Client::factory()->create(['tenant_id' => $tenant->id]);
    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
    ]);

    DocumentRequest::factory()->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $dossier->id,
        'title' => 'Only',
        'sort_order' => 0,
    ]);

    grantDossierBuilderLock($dossier, $owner);

    $this->actingAs($owner)
        ->withSession(['active_tenant_id' => $tenant->id])
        ->post(workspaceRoute('workspaces.dossiers.document-requests.store', $tenant, [
            'dossier' => $dossier,
        ]), [
            'type' => 'boolean',
            'title' => 'Appended by clamp',
            'position' => 99,
        ])
        ->assertRedirect(workspaceRoute('workspaces.dossiers.builder', $tenant, ['dossier' => $dossier]));

    expect(DocumentRequest::query()
        ->whereBelongsTo($dossier)
        ->oldest('sort_order')
        ->pluck('title')
        ->all())->toBe(['Only', 'Appended by clamp']);
});

test('document request creation rolls back when auditing fails', function (): void {
    $owner = User::factory()->create();
    $tenant = resolve(ProvisionTenantAction::class)->handle('Acme Accountants', $owner);

    $tenant->makeCurrent();
    $client = Client::factory()->create(['tenant_id' => $tenant->id]);
    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
    ]);

    $event = 'eloquent.creating: '.Activity::class;
    Event::listen($event, static function (Activity $activity): void {
        throw_if($activity->event === AuditEvent::DocumentRequestCreated->value, RuntimeException::class, 'Simulated audit failure.');
    });

    grantDossierBuilderLock($dossier, $owner);

    try {
        $this->actingAs($owner)
            ->withSession(['active_tenant_id' => $tenant->id])
            ->post(workspaceRoute('workspaces.dossiers.document-requests.store', $tenant, [
                'dossier' => $dossier,
            ]), [
                'type' => 'file',
                'title' => 'Payslip January 2025',
                'instructions' => 'Upload the original PDF.',
            ])
            ->assertServerError();
    } finally {
        Event::forget($event);
    }

    $tenant->makeCurrent();

    expect(DocumentRequest::query()->whereBelongsTo($dossier)->exists())->toBeFalse();
});

test('staff can reorder every document request in a dossier', function (): void {
    $owner = User::factory()->create();
    $tenant = resolve(ProvisionTenantAction::class)->handle('Acme Accountants', $owner);

    $tenant->makeCurrent();
    $client = Client::factory()->create(['tenant_id' => $tenant->id]);
    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
    ]);
    $otherDossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
    ]);

    $documentRequests = collect(range(0, 2))
        ->map(fn (int $sortOrder): DocumentRequest => DocumentRequest::factory()->create([
            'tenant_id' => $tenant->id,
            'dossier_id' => $dossier->id,
            'sort_order' => $sortOrder,
        ]));
    $foreignDocumentRequest = DocumentRequest::factory()->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $otherDossier->id,
    ]);
    $reorderedIds = $documentRequests->pluck('id')->reverse()->values()->all();
    grantDossierBuilderLock($dossier, $owner);
    $builderRoute = workspaceRoute('workspaces.dossiers.builder', $tenant, ['dossier' => $dossier]);
    $reorderRoute = workspaceRoute('workspaces.dossiers.document-requests.reorder', $tenant, [
        'dossier' => $dossier,
    ]);

    $this->actingAs($owner)
        ->withSession(['active_tenant_id' => $tenant->id])
        ->post($reorderRoute, ['document_request_ids' => $reorderedIds])
        ->assertRedirect($builderRoute);

    expect(DocumentRequest::query()
        ->whereBelongsTo($dossier)
        ->oldest('sort_order')
        ->pluck('id')
        ->all())->toBe($reorderedIds);

    $this->from($builderRoute)
        ->post($reorderRoute, [
            'document_request_ids' => [
                $reorderedIds[0],
                $reorderedIds[1],
                $foreignDocumentRequest->id,
            ],
        ])
        ->assertRedirect($builderRoute)
        ->assertSessionHasErrors('document_request_ids');

    expect(DocumentRequest::query()
        ->whereBelongsTo($dossier)
        ->oldest('sort_order')
        ->pluck('id')
        ->all())->toBe($reorderedIds);
});

test('staff cannot attach a dossier to a client in another tenant', function (): void {
    $ownerA = User::factory()->create();
    $ownerB = User::factory()->create();
    $tenantA = resolve(ProvisionTenantAction::class)->handle('Tenant A', $ownerA);
    $tenantB = resolve(ProvisionTenantAction::class)->handle('Tenant B', $ownerB);

    $tenantB->makeCurrent();
    $foreignClient = Client::factory()->create(['tenant_id' => $tenantB->id]);

    $this->actingAs($ownerA)
        ->withSession(['active_tenant_id' => $tenantA->id])
        ->post(workspaceRoute('workspaces.dossiers.store', $tenantA), [
            'client_id' => $foreignClient->id,
            'title' => 'Invalid dossier',
        ])
        ->assertSessionHasErrors('client_id');
});

test('read only staff cannot create clients or dossiers', function (): void {
    $owner = User::factory()->create();
    $reader = User::factory()->create();
    $tenant = resolve(ProvisionTenantAction::class)->handle('Acme Accountants', $owner);

    // Membership is required so the 403 comes from policy, not tenant middleware.
    TenantMembership::query()->create([
        'tenant_id' => $tenant->id,
        'user_id' => $reader->id,
        'status' => TenantMembershipStatus::Active,
        'joined_at' => now(),
    ]);

    $tenant->makeCurrent();
    setPermissionsTeamId($tenant->id);
    $reader->unsetRelation('roles');
    $reader->assignRole(Role::ReadOnly->value);

    $this->actingAs($reader)
        ->withSession(['active_tenant_id' => $tenant->id])
        ->post(workspaceRoute('workspaces.clients.store', $tenant), [
            'name' => 'Jane Client',
            'email' => 'jane@example.com',
        ])
        ->assertForbidden();

    $this->post(workspaceRoute('workspaces.dossiers.store', $tenant), [
        'client_id' => 1,
        'title' => 'Should not create',
    ])->assertForbidden();
});

test('staff can issue and revoke a client access grant', function (): void {
    Notification::fake();

    $owner = User::factory()->create();
    $tenant = resolve(ProvisionTenantAction::class)->handle('Acme Accountants', $owner);

    $tenant->makeCurrent();
    $client = Client::factory()->create(['tenant_id' => $tenant->id]);
    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
    ]);

    $this->actingAs($owner)
        ->withSession(['active_tenant_id' => $tenant->id])
        ->post(workspaceRoute('workspaces.dossiers.access-grants.store', $tenant, [
            'dossier' => $dossier,
        ]), [
            'expires_in_days' => 7,
            'send_invite' => false,
        ])
        ->assertRedirect(workspaceRoute('workspaces.dossiers.show', $tenant, ['dossier' => $dossier]))
        ->assertSessionHas('access_grant_token')
        ->assertSessionHas('access_grant_portal_url');

    $grant = ClientAccessGrant::query()->sole();
    expect($grant->isValid())->toBeTrue()
        ->and($grant->created_by)->toBe($owner->id);

    $this->delete(workspaceRoute('workspaces.access-grants.destroy', $tenant, ['grant' => $grant]))
        ->assertRedirect(workspaceRoute('workspaces.dossiers.show', $tenant, ['dossier' => $dossier]));

    expect($grant->fresh()->isValid())->toBeFalse()
        ->and($grant->fresh()->revoked_at)->not->toBeNull();
});

test('read only staff cannot issue client access grants', function (): void {
    $owner = User::factory()->create();
    $reader = User::factory()->create();
    $tenant = resolve(ProvisionTenantAction::class)->handle('Acme Accountants', $owner);

    TenantMembership::query()->create([
        'tenant_id' => $tenant->id,
        'user_id' => $reader->id,
        'status' => TenantMembershipStatus::Active,
        'joined_at' => now(),
    ]);

    $tenant->makeCurrent();
    setPermissionsTeamId($tenant->id);
    $reader->unsetRelation('roles');
    $reader->assignRole(Role::ReadOnly->value);

    $client = Client::factory()->create(['tenant_id' => $tenant->id]);
    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
    ]);

    $this->actingAs($reader)
        ->withSession(['active_tenant_id' => $tenant->id])
        ->post(workspaceRoute('workspaces.dossiers.access-grants.store', $tenant, [
            'dossier' => $dossier,
        ]))
        ->assertForbidden();
});

test('staff can upload a document for a document request', function (): void {
    config(['filesystems.default' => 'local']);
    Storage::fake('local');

    $owner = User::factory()->create();
    $tenant = resolve(ProvisionTenantAction::class)->handle('Acme Accountants', $owner);

    $tenant->makeCurrent();
    $client = Client::factory()->create(['tenant_id' => $tenant->id]);
    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
    ]);
    $documentRequest = DocumentRequest::factory()->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $dossier->id,
        'title' => 'Payslip January 2025',
    ]);

    $file = UploadedFile::fake()->create('payslip.pdf', 120, 'application/pdf');

    $this->actingAs($owner)
        ->withSession(['active_tenant_id' => $tenant->id])
        ->post(workspaceRoute('workspaces.dossiers.document-requests.upload', $tenant, [
            'dossier' => $dossier,
            'documentRequest' => $documentRequest,
        ]), [
            'document' => $file,
        ])
        ->assertRedirect(workspaceRoute('workspaces.dossiers.review', $tenant, ['dossier' => $dossier]));

    $uploaded = UploadedDocument::query()->sole();

    expect($documentRequest->fresh()->status)->toBe(DocumentRequestStatus::Submitted)
        ->and($dossier->fresh()->status)->toBe(DossierStatus::InReview)
        ->and($uploaded->original_filename)->toBe('payslip.pdf')
        ->and($uploaded->document_request_id)->toBe($documentRequest->id);

    Storage::disk($uploaded->disk)->assertExists($uploaded->path);

    $this->withSession(['auth.password_confirmed_at' => now()->getTimestamp()])
        ->get(workspaceRoute('workspaces.uploaded-documents.download', $tenant, [
            'uploadedDocument' => $uploaded,
        ]))
        ->assertOk();

    expect(Activity::query()
        ->where('event', AuditEvent::DocumentUploaded->value)
        ->exists())->toBeTrue();
});

test('read only staff cannot upload documents', function (): void {
    Storage::fake('local');

    $owner = User::factory()->create();
    $reader = User::factory()->create();
    $tenant = resolve(ProvisionTenantAction::class)->handle('Acme Accountants', $owner);

    TenantMembership::query()->create([
        'tenant_id' => $tenant->id,
        'user_id' => $reader->id,
        'status' => TenantMembershipStatus::Active,
        'joined_at' => now(),
    ]);

    $tenant->makeCurrent();
    setPermissionsTeamId($tenant->id);
    $reader->unsetRelation('roles');
    $reader->assignRole(Role::ReadOnly->value);

    $client = Client::factory()->create(['tenant_id' => $tenant->id]);
    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
    ]);
    $documentRequest = DocumentRequest::factory()->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $dossier->id,
    ]);
    $textRequest = DocumentRequest::factory()->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $dossier->id,
        'type' => QuestionnaireItemType::Text,
    ]);

    $this->actingAs($reader)
        ->withSession(['active_tenant_id' => $tenant->id])
        ->post(workspaceRoute('workspaces.dossiers.document-requests.upload', $tenant, [
            'dossier' => $dossier,
            'documentRequest' => $documentRequest,
        ]), [
            'document' => UploadedFile::fake()->create('payslip.pdf', 120, 'application/pdf'),
        ])
        ->assertForbidden();

    $this->post(workspaceRoute('workspaces.dossiers.document-requests.answer', $tenant, [
        'dossier' => $dossier,
        'documentRequest' => $textRequest,
    ]), [
        'answer_text' => 'Received by phone.',
    ])->assertForbidden();
});

test('staff can answer text and boolean requests on behalf of a client', function (): void {
    $owner = User::factory()->create();
    $tenant = resolve(ProvisionTenantAction::class)->handle('Acme Accountants', $owner);

    $tenant->makeCurrent();
    $client = Client::factory()->create(['tenant_id' => $tenant->id]);
    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
    ]);
    $textRequest = DocumentRequest::factory()->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $dossier->id,
        'type' => QuestionnaireItemType::Text,
    ]);
    $booleanRequest = DocumentRequest::factory()->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $dossier->id,
        'type' => QuestionnaireItemType::Boolean,
    ]);

    $route = fn (DocumentRequest $documentRequest): string => workspaceRoute(
        'workspaces.dossiers.document-requests.answer',
        $tenant,
        [
            'dossier' => $dossier,
            'documentRequest' => $documentRequest,
        ],
    );

    $this->actingAs($owner)
        ->withSession(['active_tenant_id' => $tenant->id])
        ->post($route($textRequest), ['answer_text' => 'Received by email.'])
        ->assertRedirect();

    $this->post($route($booleanRequest), ['answer_boolean' => false])
        ->assertRedirect();

    expect($textRequest->fresh())
        ->answer_text->toBe('Received by email.')
        ->status->toBe(DocumentRequestStatus::Submitted)
        ->and($booleanRequest->fresh())
        ->answer_boolean->toBeFalse()
        ->status->toBe(DocumentRequestStatus::Submitted)
        ->and($dossier->fresh()->status)->toBe(DossierStatus::InReview)
        ->and(Activity::query()
            ->where('event', AuditEvent::QuestionnaireAnswerSubmitted->value)
            ->where('properties->source', 'staff')
            ->count())->toBe(2);
});

test('staff can create and answer textarea date and number requests', function (
    QuestionnaireItemType $type,
    string $answer,
): void {
    $owner = User::factory()->create();
    $tenant = resolve(ProvisionTenantAction::class)->handle('Acme Accountants', $owner);

    $tenant->makeCurrent();
    $client = Client::factory()->create(['tenant_id' => $tenant->id]);
    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
    ]);

    grantDossierBuilderLock($dossier, $owner);

    $this->actingAs($owner)
        ->withSession(['active_tenant_id' => $tenant->id])
        ->post(workspaceRoute('workspaces.dossiers.document-requests.store', $tenant, [
            'dossier' => $dossier,
        ]), [
            'type' => $type->value,
            'title' => 'New '.$type->value.' field',
        ])
        ->assertRedirect();

    $documentRequest = $dossier->documentRequests()->where('type', $type)->firstOrFail();

    $this->post(workspaceRoute('workspaces.dossiers.document-requests.answer', $tenant, [
        'dossier' => $dossier,
        'documentRequest' => $documentRequest,
    ]), [
        'answer_text' => $answer,
    ])->assertRedirect();

    expect($documentRequest->fresh())
        ->type->toBe($type)
        ->answer_text->toBe($answer)
        ->status->toBe(DocumentRequestStatus::Submitted);
})->with([
    'textarea' => [QuestionnaireItemType::Textarea, "Paragraph one.\nParagraph two."],
    'date' => [QuestionnaireItemType::Date, '2026-07-24'],
    'number' => [QuestionnaireItemType::Number, '12.5'],
]);
