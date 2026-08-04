<?php

declare(strict_types=1);

use App\Accounts\Models\User;
use App\ClientPortal\Actions\CreateClientAccessGrantAction;
use App\ClientPortal\Models\ClientAccessGrant;
use App\Clients\Models\Client;
use App\Dossiers\Enums\DocumentRequestStatus;
use App\Dossiers\Models\DocumentRequest;
use App\Dossiers\Models\Dossier;
use App\Dossiers\Models\UploadedDocument;
use App\Tenancy\Enums\Role;
use App\Tenancy\Models\Tenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('local');
    Queue::fake();
    config()->set('filesystems.default', 'local');
});

/**
 * @return array{owner: User, tenant: Tenant, dossier: Dossier}
 */
function createMultiFileUploadWorkspace(): array
{
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();

    $client = Client::factory()->create(['tenant_id' => $tenant->id]);
    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
    ]);

    return ['owner' => $owner, 'tenant' => $tenant, 'dossier' => $dossier];
}

function makeMultiFileDocumentRequest(Tenant $tenant, Dossier $dossier, bool $allowMultiple = true): DocumentRequest
{
    return DocumentRequest::factory()->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $dossier->id,
        'title' => 'Payslips',
        'status' => DocumentRequestStatus::Pending,
        'configuration' => ['is_required' => true, 'allow_multiple' => $allowMultiple],
    ]);
}

test('staff can upload multiple files to a request that allows multiple', function (): void {
    $context = createMultiFileUploadWorkspace();
    $documentRequest = makeMultiFileDocumentRequest($context['tenant'], $context['dossier']);

    $this->actingAs($context['owner'])
        ->withSession(['active_tenant_id' => $context['tenant']->id])
        ->post(workspaceRoute('workspaces.dossiers.document-requests.upload', $context['tenant'], [
            'dossier' => $context['dossier'],
            'documentRequest' => $documentRequest,
        ]), [
            'documents' => [
                UploadedFile::fake()->create('payslip-jan.pdf', 100, 'application/pdf'),
                UploadedFile::fake()->create('payslip-feb.pdf', 100, 'application/pdf'),
            ],
        ])
        ->assertRedirect(workspaceRoute('workspaces.dossiers.review', $context['tenant'], [
            'dossier' => $context['dossier'],
        ]));

    expect($documentRequest->fresh()->status)->toBe(DocumentRequestStatus::Submitted)
        ->and(UploadedDocument::query()->where('document_request_id', $documentRequest->id)->count())->toBe(2);
});

test('staff upload is capped at one file when a request does not allow multiple', function (): void {
    $context = createMultiFileUploadWorkspace();
    $documentRequest = makeMultiFileDocumentRequest($context['tenant'], $context['dossier'], allowMultiple: false);

    $this->actingAs($context['owner'])
        ->withSession(['active_tenant_id' => $context['tenant']->id])
        ->post(workspaceRoute('workspaces.dossiers.document-requests.upload', $context['tenant'], [
            'dossier' => $context['dossier'],
            'documentRequest' => $documentRequest,
        ]), [
            'documents' => [
                UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'),
                UploadedFile::fake()->create('b.pdf', 10, 'application/pdf'),
            ],
        ])
        ->assertInvalid(['documents']);

    expect(UploadedDocument::query()->where('document_request_id', $documentRequest->id)->exists())->toBeFalse();
});

test('staff can remove one uploaded file from a multi-file request', function (): void {
    $context = createMultiFileUploadWorkspace();
    $documentRequest = makeMultiFileDocumentRequest($context['tenant'], $context['dossier']);
    $documentRequest->update(['status' => DocumentRequestStatus::Submitted]);

    $kept = UploadedDocument::factory()->create([
        'tenant_id' => $context['tenant']->id,
        'document_request_id' => $documentRequest->id,
    ]);
    $removed = UploadedDocument::factory()->create([
        'tenant_id' => $context['tenant']->id,
        'document_request_id' => $documentRequest->id,
    ]);
    Storage::disk('local')->put($removed->path, 'contents');

    $this->actingAs($context['owner'])
        ->withSession(['active_tenant_id' => $context['tenant']->id])
        ->delete(workspaceRoute('workspaces.uploaded-documents.destroy', $context['tenant'], [
            'uploadedDocument' => $removed,
        ]))
        ->assertRedirect();

    expect(UploadedDocument::query()->whereKey($removed->id)->exists())->toBeFalse()
        ->and(UploadedDocument::query()->whereKey($kept->id)->exists())->toBeTrue()
        ->and($documentRequest->fresh()->status)->toBe(DocumentRequestStatus::Submitted);

    Storage::disk('local')->assertMissing($removed->path);
});

test('removing the last uploaded file reopens a multi-file request for submission', function (): void {
    $context = createMultiFileUploadWorkspace();
    $documentRequest = makeMultiFileDocumentRequest($context['tenant'], $context['dossier']);
    $documentRequest->update(['status' => DocumentRequestStatus::Submitted, 'answered_at' => now()]);

    $onlyFile = UploadedDocument::factory()->create([
        'tenant_id' => $context['tenant']->id,
        'document_request_id' => $documentRequest->id,
    ]);

    $this->actingAs($context['owner'])
        ->withSession(['active_tenant_id' => $context['tenant']->id])
        ->delete(workspaceRoute('workspaces.uploaded-documents.destroy', $context['tenant'], [
            'uploadedDocument' => $onlyFile,
        ]))
        ->assertRedirect();

    expect($documentRequest->fresh()->status)->toBe(DocumentRequestStatus::Pending);
});

test('reviewing a multi-file request stamps every uploaded file', function (): void {
    $context = createMultiFileUploadWorkspace();
    $reviewer = workspaceMember($context['tenant'], Role::Reviewer);

    $documentRequest = makeMultiFileDocumentRequest($context['tenant'], $context['dossier']);
    $documentRequest->update(['status' => DocumentRequestStatus::Submitted]);

    $first = UploadedDocument::factory()->create([
        'tenant_id' => $context['tenant']->id,
        'document_request_id' => $documentRequest->id,
    ]);
    $second = UploadedDocument::factory()->create([
        'tenant_id' => $context['tenant']->id,
        'document_request_id' => $documentRequest->id,
    ]);

    $this->actingAs($reviewer)
        ->withSession(['active_tenant_id' => $context['tenant']->id])
        ->post(workspaceRoute('workspaces.dossiers.document-requests.review', $context['tenant'], [
            'dossier' => $context['dossier'],
            'documentRequest' => $documentRequest,
        ]), [
            'decision' => DocumentRequestStatus::Accepted->value,
        ])
        ->assertRedirect();

    expect($first->fresh()->reviewed_by)->toBe($reviewer->id)
        ->and($second->fresh()->reviewed_by)->toBe($reviewer->id)
        ->and($first->fresh()->reviewed_at)->not->toBeNull()
        ->and($second->fresh()->reviewed_at)->not->toBeNull();
});

test('staff can toggle allow_multiple for a document request in the builder', function (): void {
    $context = createMultiFileUploadWorkspace();
    $documentRequest = makeMultiFileDocumentRequest($context['tenant'], $context['dossier'], allowMultiple: false);

    grantDossierBuilderLock($context['dossier'], $context['owner']);

    $this->actingAs($context['owner'])
        ->withSession(['active_tenant_id' => $context['tenant']->id])
        ->putJson(workspaceRoute('workspaces.dossiers.document-requests.update', $context['tenant'], [
            'dossier' => $context['dossier'],
            'documentRequest' => $documentRequest,
        ]), [
            'type' => 'file',
            'title' => $documentRequest->title,
            'configuration' => ['is_required' => true, 'allow_multiple' => true],
        ])
        ->assertOk()
        ->assertJsonPath('document_request.configuration.allow_multiple', true);

    expect($documentRequest->fresh()->configuration['allow_multiple'])->toBeTrue();
});

/**
 * @return array{tenant: Tenant, dossier: Dossier, plainTextToken: string, grant: ClientAccessGrant}
 */
function createMultiFilePortalWorkspace(): array
{
    $context = createMultiFileUploadWorkspace();

    $result = resolve(CreateClientAccessGrantAction::class)->handle($context['dossier'], $context['owner'], 7);

    return [
        'tenant' => $context['tenant'],
        'dossier' => $context['dossier']->fresh(),
        'plainTextToken' => $result['plain_text_token'],
        'grant' => $result['grant'],
    ];
}

test('portal client can upload multiple files in one submission', function (): void {
    $context = createMultiFilePortalWorkspace();
    $documentRequest = makeMultiFileDocumentRequest($context['tenant'], $context['dossier']);

    $this->get(route('portal.exchange', $context['plainTextToken']));

    $this->post(route('portal.document-requests.upload', ['documentRequest' => $documentRequest->id]), [
        'documents' => [
            UploadedFile::fake()->create('payslip-jan.pdf', 100, 'application/pdf'),
            UploadedFile::fake()->create('payslip-feb.pdf', 100, 'application/pdf'),
        ],
    ])->assertRedirect(route('portal.show'));

    $context['tenant']->makeCurrent();

    expect($documentRequest->fresh()->status)->toBe(DocumentRequestStatus::Submitted)
        ->and(UploadedDocument::query()->where('document_request_id', $documentRequest->id)->count())->toBe(2);
});

test('portal client can submit a second batch of files after the first is already submitted', function (): void {
    $context = createMultiFilePortalWorkspace();
    $documentRequest = makeMultiFileDocumentRequest($context['tenant'], $context['dossier']);

    $this->get(route('portal.exchange', $context['plainTextToken']));

    $uploadUrl = route('portal.document-requests.upload', ['documentRequest' => $documentRequest->id]);

    $this->post($uploadUrl, [
        'documents' => [UploadedFile::fake()->create('payslip-jan.pdf', 100, 'application/pdf')],
    ])->assertRedirect(route('portal.show'));

    $this->post($uploadUrl, [
        'documents' => [UploadedFile::fake()->create('payslip-feb.pdf', 100, 'application/pdf')],
    ])->assertRedirect(route('portal.show'));

    $context['tenant']->makeCurrent();

    expect(UploadedDocument::query()->where('document_request_id', $documentRequest->id)->count())->toBe(2);
});

test('portal client can remove one of their uploaded files before review', function (): void {
    $context = createMultiFilePortalWorkspace();
    $documentRequest = makeMultiFileDocumentRequest($context['tenant'], $context['dossier']);
    $documentRequest->update(['status' => DocumentRequestStatus::Submitted]);

    $kept = UploadedDocument::factory()->create([
        'tenant_id' => $context['tenant']->id,
        'document_request_id' => $documentRequest->id,
    ]);
    $removed = UploadedDocument::factory()->create([
        'tenant_id' => $context['tenant']->id,
        'document_request_id' => $documentRequest->id,
    ]);
    Storage::disk('local')->put($removed->path, 'contents');

    $this->get(route('portal.exchange', $context['plainTextToken']));

    $this->delete(route('portal.document-requests.uploaded-documents.destroy', [
        'documentRequest' => $documentRequest->id,
        'uploadedDocument' => $removed->id,
    ]))->assertRedirect(route('portal.show'));

    $context['tenant']->makeCurrent();

    expect(UploadedDocument::query()->whereKey($removed->id)->exists())->toBeFalse()
        ->and(UploadedDocument::query()->whereKey($kept->id)->exists())->toBeTrue();
});

test('portal client cannot remove a file once the request is under review', function (): void {
    $context = createMultiFilePortalWorkspace();
    $documentRequest = makeMultiFileDocumentRequest($context['tenant'], $context['dossier']);
    $documentRequest->update(['status' => DocumentRequestStatus::Accepted]);

    $uploaded = UploadedDocument::factory()->create([
        'tenant_id' => $context['tenant']->id,
        'document_request_id' => $documentRequest->id,
    ]);

    $this->get(route('portal.exchange', $context['plainTextToken']));

    $this->delete(route('portal.document-requests.uploaded-documents.destroy', [
        'documentRequest' => $documentRequest->id,
        'uploadedDocument' => $uploaded->id,
    ]))->assertNotFound();

    $context['tenant']->makeCurrent();

    expect(UploadedDocument::query()->whereKey($uploaded->id)->exists())->toBeTrue();
});
