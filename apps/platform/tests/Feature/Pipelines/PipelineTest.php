<?php

declare(strict_types=1);

use App\Clients\Models\Client;
use App\Dossiers\Models\DocumentRequest;
use App\Dossiers\Models\Dossier;
use App\Dossiers\Models\UploadedDocument;
use App\Pipelines\Actions\CreatePipelineAction;
use App\Pipelines\Enums\PipelineStatus;
use App\Pipelines\Enums\PipelineStepKey;
use App\Pipelines\Enums\PipelineStepStatus;
use App\Pipelines\Enums\PipelineType;
use App\Pipelines\Models\Pipeline;
use App\Pipelines\Support\PipelineState;
use App\Pipelines\Transitions\PipelineTransitions;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;

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
    $subject = UploadedDocument::queryWithoutTenantScope()
        ->findOrFail($pipeline->pipelineable_id);

    expect($pipeline->pipelineable_type)->toBe($subject->getMorphClass())
        ->and($pipeline->tenant_id)->toBe($subject->tenant_id);
});

test('a pipeline creates ordered steps for its polymorphic subject', function (): void {
    ['tenant' => $tenant] = provisionWorkspace();
    $document = uploadedDocumentForPipeline($tenant->id);

    $pipeline = resolve(CreatePipelineAction::class)->handle(
        $document,
        PipelineType::OcrDocument,
        metadata: ['source' => 'pilot'],
    );

    expect($pipeline->pipelineable)->toBeInstanceOf(UploadedDocument::class)
        ->and($pipeline->status)->toBe(PipelineStatus::Pending)
        ->and($pipeline->steps->pluck('key')->all())
        ->toBe([PipelineStepKey::Submit, PipelineStepKey::ProviderProcessing, PipelineStepKey::ProcessResults])
        ->and($pipeline->steps->pluck('position')->all())->toBe([0, 1, 2]);
});

test('pipeline steps record progress, heartbeats, and terminal failures', function (): void {
    ['tenant' => $tenant] = provisionWorkspace();
    $document = uploadedDocumentForPipeline($tenant->id);
    $pipeline = resolve(CreatePipelineAction::class)->handle($document, PipelineType::OcrDocument);
    $state = resolve(PipelineState::class);

    $state->start($pipeline, PipelineStepKey::Submit);
    $state->complete($pipeline, PipelineStepKey::Submit);
    $state->start($pipeline, PipelineStepKey::ProviderProcessing);
    $state->heartbeatStep($pipeline, PipelineStepKey::ProviderProcessing);
    $state->fail(
        $pipeline,
        PipelineStepKey::ProviderProcessing,
        errorCode: 'provider_timeout',
        errorMessage: 'Provider did not complete in time.',
    );

    $pipeline->refresh();
    $providerStep = $pipeline->steps()->where('key', PipelineStepKey::ProviderProcessing->value)->firstOrFail();

    expect($pipeline->status)->toBe(PipelineStatus::Failed)
        ->and($pipeline->current_stage)->toBe(PipelineStepKey::ProviderProcessing)
        ->and($providerStep->status)->toBe(PipelineStepStatus::Failed)
        ->and($providerStep->attempts)->toBe(1)
        ->and($providerStep->error_code)->toBe('provider_timeout')
        ->and($providerStep->last_activity_at)->not->toBeNull();
});

test('pipeline transitions guard step ordering and closed pipelines', function (): void {
    ['tenant' => $tenant] = provisionWorkspace();
    $document = uploadedDocumentForPipeline($tenant->id);
    $pipeline = resolve(CreatePipelineAction::class)->handle($document, PipelineType::OcrDocument);
    $state = resolve(PipelineState::class);

    expect(fn () => $state->complete($pipeline, PipelineStepKey::Submit))
        ->toThrow(ValidationException::class, 'Only a running pipeline step can be completed.');

    $state->start($pipeline, PipelineStepKey::Submit);
    $state->complete($pipeline, PipelineStepKey::Submit);
    $state->start($pipeline, PipelineStepKey::ProviderProcessing);
    $state->complete($pipeline, PipelineStepKey::ProviderProcessing);
    $state->start($pipeline, PipelineStepKey::ProcessResults);
    $state->complete($pipeline, PipelineStepKey::ProcessResults);

    expect($pipeline->fresh()->status)->toBe(PipelineStatus::Completed)
        ->and(fn () => resolve(PipelineTransitions::class)->fail(
            $pipeline->fresh(),
            'late failure',
            'late_failure',
        ))
        ->toThrow(ValidationException::class, 'A completed pipeline cannot be failed.');
});

test('pipeline records are isolated by tenant', function (): void {
    ['tenant' => $tenantA] = provisionWorkspace('First Workspace');
    $document = uploadedDocumentForPipeline($tenantA->id);
    $pipeline = resolve(CreatePipelineAction::class)->handle($document, PipelineType::OcrDocument);

    ['tenant' => $tenantB] = provisionWorkspace('Second Workspace');

    expect(Pipeline::query()->find($pipeline->id))->toBeNull();

    actingInWorkspace($tenantA);

    expect(Pipeline::query()->find($pipeline->id))->not->toBeNull();
});

test('the monitor marks inactive pipelines stuck and reports them', function (): void {
    $this->travelBack();

    ['tenant' => $tenant] = provisionWorkspace();
    $document = uploadedDocumentForPipeline($tenant->id);
    $pipeline = resolve(CreatePipelineAction::class)->handle($document, PipelineType::OcrDocument);
    resolve(PipelineState::class)->start($pipeline, PipelineStepKey::Submit);

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
