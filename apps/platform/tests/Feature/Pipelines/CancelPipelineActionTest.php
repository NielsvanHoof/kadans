<?php

declare(strict_types=1);

use App\Audit\Enums\AuditEvent;
use App\Audit\Models\Activity;
use App\Clients\Models\Client;
use App\DocumentProcessing\Enums\DocumentProcessingStatus;
use App\DocumentProcessing\Jobs\ProcessUploadedDocumentJob;
use App\Dossiers\Models\DocumentRequest;
use App\Dossiers\Models\Dossier;
use App\Dossiers\Models\UploadedDocument;
use App\Pipelines\Actions\CancelPipelineAction;
use App\Pipelines\Enums\PipelineStatus;
use App\Pipelines\Enums\PipelineStepKey;
use App\Pipelines\Enums\PipelineStepStatus;
use App\Pipelines\Enums\PipelineType;
use App\Pipelines\Events\PipelineUpdated;
use App\Pipelines\Models\Pipeline;
use App\Pipelines\Support\PipelineState;
use App\Tenancy\Enums\Role;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

covers(CancelPipelineAction::class);

/*
|--------------------------------------------------------------------------
| CancelPipelineAction
|--------------------------------------------------------------------------
|
| Cancelling has to close the run AND release its subject. Every processing job
| claims its subject and then skips silently while that subject still looks in
| flight, so a cancel that forgets the reset leaves the document wedged in
| `processing` with nothing left to move it on — the same trap retry has.
|
*/

beforeEach(function (): void {
    Storage::fake('local');
    config(['ocr.driver' => 'mock']);

    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();
    $this->owner = $owner;
    $this->tenant = $tenant;
    $this->cancel = resolve(CancelPipelineAction::class);
});

test(
    'closes a running run and skips the steps that never happened',
    function (): void {
        $document = cancellableDocument();
        $pipeline = cancellablePipeline($document);
        resolve(PipelineState::class)->start(
            $pipeline,
            PipelineStepKey::Submit,
        );
        resolve(PipelineState::class)->complete(
            $pipeline,
            PipelineStepKey::Submit,
        );
        resolve(PipelineState::class)->start(
            $pipeline,
            PipelineStepKey::ProviderProcessing,
        );

        $this->cancel->handle($pipeline->fresh(), $this->owner);

        expect($pipeline->fresh())
            ->status->toBe(PipelineStatus::Cancelled)
            ->current_stage->toBeNull()
            ->completed_at->toBeNull()
            ->and(
                $pipeline
                    ->fresh()
                    ->steps->sortBy('position')
                    ->pluck('status')
                    ->all(),
            )
            ->toBe([
                PipelineStepStatus::Completed,
                PipelineStepStatus::Skipped,
                PipelineStepStatus::Skipped,
            ]);
    },
);

test(
    'releases a subject that was left mid-flight so it is not wedged in processing',
    function (): void {
        Queue::fake();
        $document = cancellableDocument();
        $document
            ->forceFill([
                'processing_status' => DocumentProcessingStatus::Processing,
                'textract_job_id' => 'stale-textract-job',
                'processing_started_at' => now()->subMinutes(30),
            ])
            ->saveQuietly();
        $pipeline = cancellablePipeline($document);

        $this->cancel->handle($pipeline, $this->owner);

        expect($document->fresh())
            ->processing_status->toBe(DocumentProcessingStatus::Pending)
            ->textract_job_id->toBeNull()
            ->processing_started_at->toBeNull();

        // Cancelling must not re-queue the work it just stopped.
        Queue::assertNotPushed(ProcessUploadedDocumentJob::class);
    },
);

test(
    'still closes the run when the subject has already been purged',
    function (): void {
        $document = cancellableDocument();
        $pipeline = cancellablePipeline($document);

        $document->forceDelete();

        $this->cancel->handle($pipeline->fresh(), $this->owner);

        expect($pipeline->fresh()->status)->toBe(PipelineStatus::Cancelled);
    },
);

test('refuses to cancel a run that is already closed', function (
    PipelineStatus $status,
): void {
    $pipeline = cancellablePipeline(cancellableDocument());
    Pipeline::query()
        ->whereKey($pipeline->getKey())
        ->update(['status' => $status->value]);

    expect(fn () => $this->cancel->handle($pipeline, $this->owner))->toThrow(
        ValidationException::class,
        'Only a pending or running pipeline can be cancelled.',
    );
})->with([
    'completed' => [PipelineStatus::Completed],
    'failed' => [PipelineStatus::Failed],
    'stuck' => [PipelineStatus::Stuck],
    'cancelled' => [PipelineStatus::Cancelled],
]);

test('records an audit entry and broadcasts the closed run', function (): void {
    $pipeline = cancellablePipeline(cancellableDocument());
    resolve(PipelineState::class)->start($pipeline, PipelineStepKey::Submit);

    Event::fake([PipelineUpdated::class]);

    $this->cancel->handle($pipeline->fresh(), $this->owner);

    Event::assertDispatched(
        fn (PipelineUpdated $event): bool => $event->pipelineId ===
            $pipeline->id &&
            $event->status === PipelineStatus::Cancelled->value,
    );

    $activity = Activity::query()
        ->where('event', AuditEvent::PipelineCancelled->value)
        ->where('subject_id', $pipeline->id)
        ->sole();

    expect($activity->causer_id)
        ->toBe($this->owner->id)
        ->and($activity->getProperty('previous_status'))
        ->toBe(PipelineStatus::Running->value)
        ->and($activity->getProperty('cancelled_at_stage'))
        ->toBe(PipelineStepKey::Submit->value);
});

describe('authorization', function (): void {
    it(
        'lets a permitted user cancel an open run from the show page',
        function (): void {
            $pipeline = cancellablePipeline(cancellableDocument());

            $this->actingAs($this->owner)
                ->post(
                    workspaceRoute(
                        'workspaces.pipelines.cancel',
                        $this->tenant,
                        ['pipeline' => $pipeline],
                    ),
                )
                ->assertRedirect(
                    workspaceRoute('workspaces.pipelines.show', $this->tenant, [
                        'pipeline' => $pipeline,
                    ]),
                );

            expect($pipeline->fresh()->status)->toBe(PipelineStatus::Cancelled);
        },
    );

    // Cancel defaults to Administrator + Adviser, so a Reviewer must be refused.
    it(
        'forbids a member whose role does not carry the cancel permission',
        function (): void {
            $member = workspaceMember($this->tenant, Role::Reviewer);
            $pipeline = cancellablePipeline(cancellableDocument());

            $this->actingAs($member)
                ->post(
                    workspaceRoute(
                        'workspaces.pipelines.cancel',
                        $this->tenant,
                        ['pipeline' => $pipeline],
                    ),
                )
                ->assertForbidden();

            expect($pipeline->fresh()->status)->toBe(PipelineStatus::Pending);
        },
    );

    it('forbids cancelling a run that is already closed', function (): void {
        $pipeline = cancellablePipeline(cancellableDocument());
        $pipeline
            ->forceFill(['status' => PipelineStatus::Completed])
            ->saveQuietly();

        $this->actingAs($this->owner)
            ->post(
                workspaceRoute('workspaces.pipelines.cancel', $this->tenant, [
                    'pipeline' => $pipeline,
                ]),
            )
            ->assertForbidden();
    });

    it(
        'exposes can_cancel on the show page only while the run is open',
        function (): void {
            $open = cancellablePipeline(cancellableDocument());
            $closed = cancellablePipeline(cancellableDocument());
            $closed
                ->forceFill(['status' => PipelineStatus::Completed])
                ->saveQuietly();

            $this->actingAs($this->owner)
                ->get(
                    workspaceRoute('workspaces.pipelines.show', $this->tenant, [
                        'pipeline' => $open,
                    ]),
                )
                ->assertOk()
                ->assertInertia(fn ($page) => $page->where('can_cancel', true));

            $this->actingAs($this->owner)
                ->get(
                    workspaceRoute('workspaces.pipelines.show', $this->tenant, [
                        'pipeline' => $closed,
                    ]),
                )
                ->assertOk()
                ->assertInertia(fn ($page) => $page->where('can_cancel', false));
        },
    );
});

function cancellableDocument(): UploadedDocument
{
    $client = Client::factory()->create(['tenant_id' => test()->tenant->id]);
    $dossier = Dossier::factory()->create([
        'tenant_id' => test()->tenant->id,
        'client_id' => $client->id,
    ]);
    $documentRequest = DocumentRequest::factory()->create([
        'tenant_id' => test()->tenant->id,
        'dossier_id' => $dossier->id,
    ]);

    return UploadedDocument::factory()->create([
        'tenant_id' => test()->tenant->id,
        'document_request_id' => $documentRequest->id,
    ]);
}

function cancellablePipeline(UploadedDocument $document): Pipeline
{
    $pipeline = Pipeline::factory()->create([
        'tenant_id' => test()->tenant->id,
        'type' => PipelineType::OcrDocument,
        'status' => PipelineStatus::Pending,
        'pipelineable_type' => $document->getMorphClass(),
        'pipelineable_id' => $document->id,
    ]);

    foreach (PipelineType::OcrDocument->stepKeys() as $position => $key) {
        $pipeline->steps()->create([
            'tenant_id' => test()->tenant->id,
            'key' => $key,
            'position' => $position,
        ]);
    }

    return $pipeline->fresh();
}
