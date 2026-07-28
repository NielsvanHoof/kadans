<?php

declare(strict_types=1);

use App\Actions\Pipelines\CreatePipelineAction;
use App\Actions\Pipelines\PipelineStateAction;
use App\Enums\PipelineStepOperation;
use App\Enums\PipelineType;
use App\Enums\Role;
use App\Models\Activity;
use App\Models\Client;
use App\Models\DocumentRequest;
use App\Models\Dossier;
use App\Models\Pipeline;
use App\Models\UploadedDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

test('an authorized workspace member can view pipeline index and details', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();
    $document = pipelineDocument($tenant->id);
    $pipeline = app(CreatePipelineAction::class)->handle($document, PipelineType::OcrDocument);
    app(PipelineStateAction::class)->handle($pipeline, PipelineStepOperation::Start, 'submit');

    $this->actingAs($owner)
        ->withSession(['active_tenant_id' => $tenant->id])
        ->get(workspaceRoute('workspaces.pipelines.index', $tenant))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('workspaces/pipelines/index')
            ->has('pipelines.data', 1)
            ->where('pipelines.data.0.id', $pipeline->id)
            ->where('pipelines.data.0.status', 'running')
            ->has('indexQuery.filters'));

    $this->actingAs($owner)
        ->withSession(['active_tenant_id' => $tenant->id])
        ->get(workspaceRoute('workspaces.pipelines.show', $tenant, ['pipeline' => $pipeline]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
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
    $pipelineA = app(CreatePipelineAction::class)->handle($documentA, PipelineType::OcrDocument);

    ['owner' => $ownerB, 'tenant' => $tenantB] = provisionWorkspace('Second Workspace');
    $documentB = pipelineDocument($tenantB->id);
    $pipelineB = app(CreatePipelineAction::class)->handle($documentB, PipelineType::OcrDocument);

    expect($pipelineA->tenant_id)->not->toBe($pipelineB->tenant_id);

    $this->actingAs($ownerA)
        ->withSession(['active_tenant_id' => $tenantA->id])
        ->get(workspaceRoute('workspaces.pipelines.index', $tenantA))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('pipelines.data', 1)
            ->where('pipelines.data.0.id', $pipelineA->id));

    $this->actingAs($ownerA)
        ->withSession(['active_tenant_id' => $tenantA->id])
        ->get(workspaceRoute('workspaces.pipelines.show', $tenantA, ['pipeline' => $pipelineB->id]))
        ->assertNotFound();
});

test('reviewers cannot view pipeline operations', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();
    $reviewer = workspaceMember($tenant, Role::Reviewer);

    $this->actingAs($reviewer)
        ->withSession(['active_tenant_id' => $tenant->id])
        ->get(workspaceRoute('workspaces.pipelines.index', $tenant))
        ->assertForbidden();

    expect($owner)->toBeInstanceOf(User::class);
});

test('owners can delete terminal pipelines and the deletion is audited', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();
    $document = pipelineDocument($tenant->id);
    $pipeline = app(CreatePipelineAction::class)->handle($document, PipelineType::OcrDocument);
    $state = app(PipelineStateAction::class);
    $state->handle($pipeline, PipelineStepOperation::Start, 'submit');
    $state->handle($pipeline, PipelineStepOperation::Fail, 'submit', errorCode: 'test_failure', errorMessage: 'Test failure.');

    $this->actingAs($owner)
        ->withSession(['active_tenant_id' => $tenant->id])
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
    $pipeline = app(CreatePipelineAction::class)->handle($document, PipelineType::OcrDocument);
    app(PipelineStateAction::class)->handle($pipeline, PipelineStepOperation::Start, 'submit');

    $this->actingAs($owner)
        ->withSession(['active_tenant_id' => $tenant->id])
        ->delete(workspaceRoute('workspaces.pipelines.destroy', $tenant, ['pipeline' => $pipeline]))
        ->assertForbidden();
});

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
