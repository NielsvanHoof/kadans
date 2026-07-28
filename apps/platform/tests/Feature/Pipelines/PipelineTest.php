<?php

declare(strict_types=1);

use App\Clients\Models\Client;
use App\DocumentProcessing\Actions\Pipelines\CreatePipelineAction;
use App\DocumentProcessing\Actions\Pipelines\PipelineStateAction;
use App\DocumentProcessing\Enums\PipelineStatus;
use App\DocumentProcessing\Enums\PipelineStepOperation;
use App\DocumentProcessing\Enums\PipelineStepStatus;
use App\DocumentProcessing\Enums\PipelineType;
use App\DocumentProcessing\Models\Pipeline;
use App\DocumentProcessing\Transitions\PipelineTransitions;
use App\Dossiers\Models\DocumentRequest;
use App\Dossiers\Models\Dossier;
use App\Dossiers\Models\UploadedDocument;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

test('a pipeline requires a polymorphic subject', function (): void {
    ['tenant' => $tenant] = provisionWorkspace();

    expect(fn () => Pipeline::factory()->create([
        'tenant_id' => $tenant->id,
        'pipelineable_type' => null,
        'pipelineable_id' => null,
    ]))->toThrow(QueryException::class);
});

test('the pipeline factory creates a tenant-consistent subject', function (): void {
    $pipeline = Pipeline::factory()->create();
    $subject = UploadedDocument::query()
        ->withoutGlobalScopes()
        ->findOrFail($pipeline->pipelineable_id);

    expect($pipeline->pipelineable_type)->toBe($subject->getMorphClass())
        ->and($pipeline->tenant_id)->toBe($subject->tenant_id);
});

test('a pipeline creates ordered steps for its polymorphic subject', function (): void {
    ['tenant' => $tenant] = provisionWorkspace();
    $document = uploadedDocumentForPipeline($tenant->id);

    $pipeline = app(CreatePipelineAction::class)->handle(
        $document,
        PipelineType::OcrDocument,
        metadata: ['source' => 'pilot'],
    );

    expect($pipeline->pipelineable)->toBeInstanceOf(UploadedDocument::class)
        ->and($pipeline->status)->toBe(PipelineStatus::Pending)
        ->and($pipeline->steps->pluck('key')->all())
        ->toBe(['submit', 'provider_processing', 'process_results'])
        ->and($pipeline->steps->pluck('position')->all())->toBe([0, 1, 2]);
});

test('pipeline steps record progress, heartbeats, and terminal failures', function (): void {
    ['tenant' => $tenant] = provisionWorkspace();
    $document = uploadedDocumentForPipeline($tenant->id);
    $pipeline = app(CreatePipelineAction::class)->handle($document, PipelineType::OcrDocument);
    $state = app(PipelineStateAction::class);

    $state->handle($pipeline, PipelineStepOperation::Start, 'submit');
    $state->handle($pipeline, PipelineStepOperation::Complete, 'submit');
    $state->handle($pipeline, PipelineStepOperation::Start, 'provider_processing');
    $state->handle($pipeline, PipelineStepOperation::Heartbeat, 'provider_processing');
    $state->handle(
        $pipeline,
        PipelineStepOperation::Fail,
        'provider_processing',
        errorCode: 'provider_timeout',
        errorMessage: 'Provider did not complete in time.',
    );

    $pipeline->refresh();
    $providerStep = $pipeline->steps()->where('key', 'provider_processing')->firstOrFail();

    expect($pipeline->status)->toBe(PipelineStatus::Failed)
        ->and($pipeline->current_stage)->toBe('provider_processing')
        ->and($providerStep->status)->toBe(PipelineStepStatus::Failed)
        ->and($providerStep->attempts)->toBe(1)
        ->and($providerStep->error_code)->toBe('provider_timeout')
        ->and($providerStep->last_activity_at)->not->toBeNull();
});

test('pipeline transitions guard step ordering and closed pipelines', function (): void {
    ['tenant' => $tenant] = provisionWorkspace();
    $document = uploadedDocumentForPipeline($tenant->id);
    $pipeline = app(CreatePipelineAction::class)->handle($document, PipelineType::OcrDocument);
    $state = app(PipelineStateAction::class);

    expect(fn () => $state->handle($pipeline, PipelineStepOperation::Complete, 'submit'))
        ->toThrow(ValidationException::class, 'Only a running pipeline step can be completed.');

    $state->handle($pipeline, PipelineStepOperation::Start, 'submit');
    $state->handle($pipeline, PipelineStepOperation::Complete, 'submit');
    $state->handle($pipeline, PipelineStepOperation::Start, 'provider_processing');
    $state->handle($pipeline, PipelineStepOperation::Complete, 'provider_processing');
    $state->handle($pipeline, PipelineStepOperation::Start, 'process_results');
    $state->handle($pipeline, PipelineStepOperation::Complete, 'process_results');

    expect($pipeline->fresh()->status)->toBe(PipelineStatus::Completed)
        ->and(fn () => app(PipelineTransitions::class)->failPipeline(
            $pipeline->fresh(),
            'late failure',
            'late_failure',
        ))
        ->toThrow(ValidationException::class, 'A completed pipeline cannot be failed.');
});

test('pipeline records are isolated by tenant', function (): void {
    ['tenant' => $tenantA] = provisionWorkspace('First Workspace');
    $document = uploadedDocumentForPipeline($tenantA->id);
    $pipeline = app(CreatePipelineAction::class)->handle($document, PipelineType::OcrDocument);

    ['tenant' => $tenantB] = provisionWorkspace('Second Workspace');

    expect(Pipeline::query()->find($pipeline->id))->toBeNull();

    actingInWorkspace($tenantA);

    expect(Pipeline::query()->find($pipeline->id))->not->toBeNull();
});

test('the monitor marks inactive pipelines stuck and reports them', function (): void {
    $this->travelBack();

    ['tenant' => $tenant] = provisionWorkspace();
    $document = uploadedDocumentForPipeline($tenant->id);
    $pipeline = app(CreatePipelineAction::class)->handle($document, PipelineType::OcrDocument);
    app(PipelineStateAction::class)->handle($pipeline, PipelineStepOperation::Start, 'submit');

    $this->travel(31)->minutes();

    $this->artisan('pipelines:monitor', ['--stuck-after' => 30])->assertSuccessful();

    $this->travelBack();

    expect($pipeline->fresh()->status)->toBe(PipelineStatus::Stuck);
});

function uploadedDocumentForPipeline(int $tenantId): UploadedDocument
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
