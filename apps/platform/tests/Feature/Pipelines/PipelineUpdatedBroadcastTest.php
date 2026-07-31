<?php

declare(strict_types=1);

use App\Clients\Models\Client;
use App\DocumentProcessing\Actions\Pipelines\CreatePipelineAction;
use App\DocumentProcessing\Actions\Pipelines\PipelineStateAction;
use App\DocumentProcessing\Enums\PipelineStepOperation;
use App\DocumentProcessing\Enums\PipelineType;
use App\DocumentProcessing\Events\PipelineUpdated;
use App\Dossiers\Models\DocumentRequest;
use App\Dossiers\Models\Dossier;
use App\Dossiers\Models\UploadedDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

pest()
    ->use(RefreshDatabase::class);

test('creating a pipeline broadcasts pipeline updated to the workspace', function (): void {
    Event::fake([PipelineUpdated::class]);

    ['tenant' => $tenant] = provisionWorkspace();
    $document = uploadedDocumentForBroadcast($tenant->id);

    $pipeline = resolve(CreatePipelineAction::class)->handle($document, PipelineType::OcrDocument);

    Event::assertDispatched(fn (PipelineUpdated $event): bool => $event->tenantSlug === $tenant->slug
        && $event->pipelineId === $pipeline->id
        && $event->type === PipelineType::OcrDocument->value
        && $event->status === 'pending'
        && $event->subjectType === 'uploaded_document'
        && $event->subjectId === $document->id
        && $event->dossierId === $document->documentRequest->dossier_id
        && $event->broadcastAs() === 'pipeline.updated');
});

test('pipeline step transitions broadcast pipeline updated', function (): void {
    Event::fake([PipelineUpdated::class]);

    ['tenant' => $tenant] = provisionWorkspace();
    $document = uploadedDocumentForBroadcast($tenant->id);
    $pipeline = resolve(CreatePipelineAction::class)->handle($document, PipelineType::OcrDocument);

    Event::fake([PipelineUpdated::class]);

    resolve(PipelineStateAction::class)->handle($pipeline, PipelineStepOperation::Start, 'submit');

    Event::assertDispatched(fn (PipelineUpdated $event): bool => $event->tenantSlug === $tenant->slug
        && $event->pipelineId === $pipeline->id
        && $event->status === 'running');
});

test('pipeline heartbeats do not broadcast pipeline updated', function (): void {
    ['tenant' => $tenant] = provisionWorkspace();
    $document = uploadedDocumentForBroadcast($tenant->id);
    $pipeline = resolve(CreatePipelineAction::class)->handle($document, PipelineType::OcrDocument);
    $state = resolve(PipelineStateAction::class);

    $state->handle($pipeline, PipelineStepOperation::Start, 'submit');

    Event::fake([PipelineUpdated::class]);

    $state->handle($pipeline, PipelineStepOperation::Heartbeat, 'submit');
    $state->handle($pipeline, PipelineStepOperation::Heartbeat);

    Event::assertNotDispatched(PipelineUpdated::class);
});

test('pipeline updated broadcasts on the workspace private channel', function (): void {
    $event = new PipelineUpdated(
        tenantSlug: 'acme',
        pipelineId: 12,
        type: PipelineType::OcrDocument->value,
        status: 'running',
        subjectType: 'uploaded_document',
        subjectId: 34,
        dossierId: 56,
    );

    expect($event->broadcastOn())->toHaveCount(1)
        ->and($event->broadcastOn()[0]->name)->toBe('private-workspaces.acme')
        ->and($event->broadcastWith())->toBe([
            'pipeline_id' => 12,
            'type' => 'ocr_document',
            'status' => 'running',
            'subject_type' => 'uploaded_document',
            'subject_id' => 34,
            'dossier_id' => 56,
        ])
        ->and($event->tags())->toBe([
            'broadcast',
            'pipeline.updated',
            'tenant:acme',
            'pipeline:12',
            'uploaded_document:34',
            'dossier:56',
        ]);
});

function uploadedDocumentForBroadcast(int $tenantId): UploadedDocument
{
    $client = Client::factory()->create(['tenant_id' => $tenantId]);
    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenantId,
        'client_id' => $client->id,
    ]);
    $documentRequest = DocumentRequest::factory()->create([
        'tenant_id' => $tenantId,
        'dossier_id' => $dossier->id,
    ]);

    return UploadedDocument::factory()->create([
        'tenant_id' => $tenantId,
        'document_request_id' => $documentRequest->id,
    ]);
}
