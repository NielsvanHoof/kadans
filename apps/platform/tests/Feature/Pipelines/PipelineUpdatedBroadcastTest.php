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
use App\Pipelines\Events\PipelineUpdated;
use App\Pipelines\Support\PipelineState;
use App\Pipelines\Support\PipelineStepSequence;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;

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

    resolve(PipelineState::class)->start($pipeline, PipelineStepKey::Submit);

    Event::assertDispatched(fn (PipelineUpdated $event): bool => $event->tenantSlug === $tenant->slug
        && $event->pipelineId === $pipeline->id
        && $event->status === 'running');
});

test('pipeline heartbeats do not broadcast pipeline updated', function (): void {
    ['tenant' => $tenant] = provisionWorkspace();
    $document = uploadedDocumentForBroadcast($tenant->id);
    $pipeline = resolve(CreatePipelineAction::class)->handle($document, PipelineType::OcrDocument);
    $state = resolve(PipelineState::class);

    $state->start($pipeline, PipelineStepKey::Submit);

    Event::fake([PipelineUpdated::class]);

    $state->heartbeatStep($pipeline, PipelineStepKey::Submit);
    $state->heartbeat($pipeline);

    Event::assertNotDispatched(PipelineUpdated::class);
});

test('pipeline updated stays off the channel every staff member is on', function (): void {
    $event = new PipelineUpdated(
        tenantSlug: 'acme',
        pipelineId: 12,
        type: PipelineType::OcrDocument->value,
        status: 'running',
        subjectType: 'uploaded_document',
        subjectId: 34,
        dossierId: 56,
    );

    $channels = array_map(
        static fn (PrivateChannel $channel): string => $channel->name,
        $event->broadcastOn(),
    );

    // The notification bell mounts in AppLayout, so `workspaces.acme` has every
    // staff member on it, on every page. Pusher delivers a channel's events to
    // all its subscribers whether or not a listener is bound, so this stream
    // being there meant one bulk upload woke every open tab in the firm.
    expect($channels)->not->toContain('private-workspaces.acme')
        ->and($channels)->toEqualCanonicalizing([
            'private-workspaces.acme.pipelines',
            'private-workspaces.acme.dossiers.56',
        ]);
});

test('a run with no dossier still reaches the firm-wide stream', function (): void {
    $event = new PipelineUpdated(
        tenantSlug: 'acme',
        pipelineId: 12,
        type: PipelineType::OcrDocument->value,
        status: 'running',
        subjectType: 'uploaded_document',
        subjectId: 34,
        // A purged subject carries no dossier tag, so there is no dossier
        // channel to publish to — but the pipeline pages must still see it.
        dossierId: null,
    );

    expect(array_map(
        static fn (PrivateChannel $channel): string => $channel->name,
        $event->broadcastOn(),
    ))->toBe(['private-workspaces.acme.pipelines']);
});

test('pipeline updated broadcasts on the scoped private channels', function (): void {
    $event = new PipelineUpdated(
        tenantSlug: 'acme',
        pipelineId: 12,
        type: PipelineType::OcrDocument->value,
        status: 'running',
        subjectType: 'uploaded_document',
        subjectId: 34,
        dossierId: 56,
    );

    expect($event->broadcastOn())->toHaveCount(2)
        ->and($event->broadcastOn()[0]->name)->toBe('private-workspaces.acme.pipelines')
        ->and($event->broadcastOn()[1]->name)->toBe('private-workspaces.acme.dossiers.56')
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

test('a batched step sequence broadcasts once, not once per step', function (): void {
    ['tenant' => $tenant] = provisionWorkspace();
    $document = uploadedDocumentForBroadcast($tenant->id);
    $pipeline = resolve(CreatePipelineAction::class)->handle($document, PipelineType::OcrDocument);

    Event::fake([PipelineUpdated::class]);

    resolve(PipelineState::class)->run($pipeline, fn (PipelineStepSequence $steps): PipelineStepSequence => $steps
        ->start(PipelineStepKey::Submit)
        ->complete(PipelineStepKey::Submit)
        ->skip(PipelineStepKey::ProviderProcessing)
        ->start(PipelineStepKey::ProcessResults)
        ->complete(PipelineStepKey::ProcessResults));

    // Five transitions, one live reload.
    Event::assertDispatchedTimes(PipelineUpdated::class, 1);

    expect($pipeline->fresh())
        ->status->toBe(PipelineStatus::Completed)
        ->and($pipeline->fresh()->steps->pluck('status')->all())->toBe([
            PipelineStepStatus::Completed,
            PipelineStepStatus::Skipped,
            PipelineStepStatus::Completed,
        ]);
});

test('a batched sequence rolls every step back together when one transition fails', function (): void {
    ['tenant' => $tenant] = provisionWorkspace();
    $document = uploadedDocumentForBroadcast($tenant->id);
    $pipeline = resolve(CreatePipelineAction::class)->handle($document, PipelineType::OcrDocument);

    Event::fake([PipelineUpdated::class]);

    // process_results was never started, so completing it is an illegal transition.
    expect(fn () => resolve(PipelineState::class)->run(
        $pipeline,
        fn (PipelineStepSequence $steps): PipelineStepSequence => $steps
            ->start(PipelineStepKey::Submit)
            ->complete(PipelineStepKey::ProcessResults),
    ))->toThrow(ValidationException::class);

    Event::assertNotDispatched(PipelineUpdated::class);

    expect($pipeline->fresh())
        ->status->toBe(PipelineStatus::Pending)
        ->and($pipeline->fresh()->steps->pluck('status')->all())->toBe([
            PipelineStepStatus::Pending,
            PipelineStepStatus::Pending,
            PipelineStepStatus::Pending,
        ]);
});
