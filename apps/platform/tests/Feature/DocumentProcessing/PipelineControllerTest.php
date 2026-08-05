<?php

declare(strict_types=1);

use App\Accounts\Models\User;
use App\Audit\Enums\AuditEvent;
use App\Audit\Models\Activity;
use App\Clients\Models\Client;
use App\DocumentProcessing\Actions\Pipelines\CreatePipelineAction;
use App\DocumentProcessing\Actions\Pipelines\PipelineStateAction;
use App\DocumentProcessing\Enums\PipelineStepOperation;
use App\DocumentProcessing\Enums\PipelineType;
use App\DocumentProcessing\Models\Pipeline;
use App\Dossiers\Models\DocumentRequest;
use App\Dossiers\Models\Dossier;
use App\Dossiers\Models\DossierExport;
use App\Dossiers\Models\UploadedDocument;
use App\Tenancy\Enums\Role;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

test('an authorized workspace member can view pipeline index and details', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();
    $document = pipelineDocument($tenant->id);
    $pipeline = resolve(CreatePipelineAction::class)->handle($document, PipelineType::OcrDocument);
    resolve(PipelineStateAction::class)->handle($pipeline, PipelineStepOperation::Start, 'submit');

    $this->actingAs($owner)
        ->get(workspaceRoute('workspaces.pipelines.index', $tenant))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('workspaces/pipelines/index')
            ->has('pipelines.data', 1)
            ->where('pipelines.data.0.id', $pipeline->id)
            ->where('pipelines.data.0.status', 'running')
            ->has('indexQuery.filters'));

    $this->actingAs($owner)
        ->get(workspaceRoute('workspaces.pipelines.show', $tenant, ['pipeline' => $pipeline]))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('workspaces/pipelines/show')
            ->where('pipeline.id', $pipeline->id)
            ->where('pipeline.subject.type', 'UploadedDocument')
            ->where('pipeline.subject.label', $document->original_filename)
            ->has('pipeline.steps', 3)
            ->where('can_delete', false));
});

test('pipeline index is tenant isolated', function (): void {
    ['owner' => $ownerA, 'tenant' => $tenantA] = provisionWorkspace('First Workspace');
    $documentA = pipelineDocument($tenantA->id);
    $pipelineA = resolve(CreatePipelineAction::class)->handle($documentA, PipelineType::OcrDocument);

    ['owner' => $ownerB, 'tenant' => $tenantB] = provisionWorkspace('Second Workspace');
    $documentB = pipelineDocument($tenantB->id);
    $pipelineB = resolve(CreatePipelineAction::class)->handle($documentB, PipelineType::OcrDocument);

    expect($pipelineA->tenant_id)->not->toBe($pipelineB->tenant_id);

    $this->actingAs($ownerA)
        ->get(workspaceRoute('workspaces.pipelines.index', $tenantA))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->has('pipelines.data', 1)
            ->where('pipelines.data.0.id', $pipelineA->id));

    $this->actingAs($ownerA)
        ->get(workspaceRoute('workspaces.pipelines.show', $tenantA, ['pipeline' => $pipelineB->id]))
        ->assertNotFound();
});

test('reviewers cannot view pipeline operations', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();
    $reviewer = workspaceMember($tenant, Role::Reviewer);

    $this->actingAs($reviewer)
        ->get(workspaceRoute('workspaces.pipelines.index', $tenant))
        ->assertForbidden();

    expect($owner)->toBeInstanceOf(User::class);
});

test('owners can delete terminal pipelines and the deletion is audited', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();
    $document = pipelineDocument($tenant->id);
    $pipeline = resolve(CreatePipelineAction::class)->handle($document, PipelineType::OcrDocument);
    $state = resolve(PipelineStateAction::class);
    $state->handle($pipeline, PipelineStepOperation::Start, 'submit');
    $state->handle($pipeline, PipelineStepOperation::Fail, 'submit', errorCode: 'test_failure', errorMessage: 'Test failure.');

    $this->actingAs($owner)
        ->delete(workspaceRoute('workspaces.pipelines.destroy', $tenant, ['pipeline' => $pipeline]))
        ->assertRedirect(workspaceRoute('workspaces.pipelines.index', $tenant));

    actingInWorkspace($tenant);

    expect(Pipeline::query()->find($pipeline->id))->toBeNull()
        ->and(Activity::query()
            ->where('event', 'pipeline.deleted')
            ->where('subject_id', $pipeline->id)
            ->exists())->toBeTrue();
});

test('active pipelines cannot be deleted', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();
    $document = pipelineDocument($tenant->id);
    $pipeline = resolve(CreatePipelineAction::class)->handle($document, PipelineType::OcrDocument);
    resolve(PipelineStateAction::class)->handle($pipeline, PipelineStepOperation::Start, 'submit');

    $this->actingAs($owner)
        ->delete(workspaceRoute('workspaces.pipelines.destroy', $tenant, ['pipeline' => $pipeline]))
        ->assertForbidden();
});

test('an export pipeline payload can be downloaded and is audited', function (): void {
    Storage::fake('local');
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();
    $export = pipelineExport($tenant->id, 'Confidential dossier');
    $pipeline = resolve(CreatePipelineAction::class)->handle($export, PipelineType::DossierExport, $owner);
    Storage::disk('local')->put((string) $export->path, 'archive');

    $this->actingAs($owner)
        ->get(workspaceRoute('workspaces.pipelines.show', $tenant, ['pipeline' => $pipeline]))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page->where('can_download', true));

    $this->actingAs($owner)
        ->withSession(['auth.password_confirmed_at' => now()->getTimestamp()])
        ->get(workspaceRoute('workspaces.pipelines.download', $tenant, ['pipeline' => $pipeline]))
        ->assertOk()
        ->assertHeader('content-disposition', 'attachment; filename=confidential-dossier-export.zip')
        ->assertHeader('content-type', 'application/zip');

    expect(Activity::query()
        ->where('event', AuditEvent::DossierExportDownloaded->value)
        ->where('subject_id', $export->id)
        ->exists())->toBeTrue();
});

test('the export payload download requires a confirmed password', function (): void {
    Storage::fake('local');
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();
    $export = pipelineExport($tenant->id);
    $pipeline = resolve(CreatePipelineAction::class)->handle($export, PipelineType::DossierExport, $owner);
    Storage::disk('local')->put((string) $export->path, 'archive');

    $this->actingAs($owner)
        ->get(workspaceRoute('workspaces.pipelines.download', $tenant, ['pipeline' => $pipeline]))
        ->assertRedirect(route('password.confirm'));
});

test('ocr pipelines and expired exports have no downloadable payload', function (): void {
    Storage::fake('local');
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();
    $document = pipelineDocument($tenant->id);
    $ocrPipeline = resolve(CreatePipelineAction::class)->handle($document, PipelineType::OcrDocument, $owner);

    $expiredExport = pipelineExport($tenant->id);
    $expiredExport->update(['expires_at' => now()->subMinute()]);
    $expiredPipeline = resolve(CreatePipelineAction::class)->handle($expiredExport, PipelineType::DossierExport, $owner);

    $session = ['auth.password_confirmed_at' => now()->getTimestamp()];

    $this->actingAs($owner)
        ->withSession($session)
        ->get(workspaceRoute('workspaces.pipelines.show', $tenant, ['pipeline' => $ocrPipeline]))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page->where('can_download', false));

    $this->actingAs($owner)
        ->withSession($session)
        ->get(workspaceRoute('workspaces.pipelines.download', $tenant, ['pipeline' => $ocrPipeline]))
        ->assertForbidden();

    $this->actingAs($owner)
        ->withSession($session)
        ->get(workspaceRoute('workspaces.pipelines.download', $tenant, ['pipeline' => $expiredPipeline]))
        ->assertForbidden();
});

test('members without download permission cannot download an export payload', function (): void {
    Storage::fake('local');
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();
    $reviewer = workspaceMember($tenant, Role::Reviewer);
    $export = pipelineExport($tenant->id);
    $pipeline = resolve(CreatePipelineAction::class)->handle($export, PipelineType::DossierExport, $owner);
    Storage::disk('local')->put((string) $export->path, 'archive');

    $this->actingAs($reviewer)
        ->withSession(['auth.password_confirmed_at' => now()->getTimestamp()])
        ->get(workspaceRoute('workspaces.pipelines.download', $tenant, ['pipeline' => $pipeline]))
        ->assertForbidden();
});

function pipelineExport(int $tenantId, string $dossierTitle = 'Test dossier'): DossierExport
{
    $client = Client::factory()->create(['tenant_id' => $tenantId]);
    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenantId,
        'client_id' => $client->id,
        'title' => $dossierTitle,
    ]);

    return DossierExport::factory()
        ->completed('dossier-exports/pipeline.zip')
        ->create([
            'tenant_id' => $tenantId,
            'dossier_id' => $dossier->id,
        ]);
}

function pipelineDocument(int $tenantId): UploadedDocument
{
    $client = Client::factory()->create(['tenant_id' => $tenantId]);
    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenantId,
        'client_id' => $client->id,
    ]);
    $request = DocumentRequest::factory()->create([
        'tenant_id' => $tenantId,
        'dossier_id' => $dossier->id,
    ]);

    return UploadedDocument::factory()->create([
        'tenant_id' => $tenantId,
        'document_request_id' => $request->id,
    ]);
}
