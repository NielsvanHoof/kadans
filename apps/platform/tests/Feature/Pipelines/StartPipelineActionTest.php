<?php

declare(strict_types=1);

use App\AudioProcessing\Jobs\ProcessAudioRecordingJob;
use App\Clients\Models\Client;
use App\DocumentProcessing\Jobs\ProcessUploadedDocumentJob;
use App\Dossiers\Jobs\GenerateDossierExportJob;
use App\Dossiers\Models\AudioRecording;
use App\Dossiers\Models\DocumentRequest;
use App\Dossiers\Models\Dossier;
use App\Dossiers\Models\DossierExport;
use App\Dossiers\Models\UploadedDocument;
use App\Pipelines\Actions\StartPipelineAction;
use App\Pipelines\Enums\PipelineStatus;
use App\Pipelines\Enums\PipelineStepStatus;
use App\Pipelines\Enums\PipelineType;
use App\Pipelines\Models\Pipeline;
use App\Pipelines\Support\PipelineRunnerRegistry;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

covers(StartPipelineAction::class, PipelineRunnerRegistry::class);

/*
|--------------------------------------------------------------------------
| StartPipelineAction
|--------------------------------------------------------------------------
|
| Opening a run and queueing its first attempt used to be hand-rolled at every
| upload site, which let the first attempt drift from what RetryPipelineAction
| queues for the same type. Both now go through the type's registered
| PipelineRunner, so what these tests pin down is the queued job per type —
| exactly what drifted before.
|
*/

beforeEach(function (): void {
    Storage::fake('local');
    config(['ocr.driver' => 'mock', 'transcription.driver' => 'mock']);
    Queue::fake();

    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();
    $this->owner = $owner;
    $this->tenant = $tenant;
    $this->start = resolve(StartPipelineAction::class);
});

it('opens a pending run with every step of its type seeded', function (): void {
    $document = startableDocument();

    $pipeline = $this->start->handle($document, PipelineType::OcrDocument, $this->owner, ['source' => 'staff']);

    expect($pipeline)
        ->status->toBe(PipelineStatus::Pending)
        ->type->toBe(PipelineType::OcrDocument)
        ->initiated_by->toBe($this->owner->id)
        ->pipelineable_id->toBe($document->id)
        ->and($pipeline->metadata)->toBe(['source' => 'staff'])
        ->and($pipeline->steps->sortBy('position')->pluck('key')->all())
        ->toBe(PipelineType::OcrDocument->stepKeys())
        ->and($pipeline->steps->every(
            fn ($step): bool => $step->status === PipelineStepStatus::Pending,
        ))->toBeTrue();
});

it('queues the OCR job with the document it has to read', function (): void {
    $document = startableDocument();

    $pipeline = $this->start->handle($document, PipelineType::OcrDocument);

    Queue::assertPushed(
        ProcessUploadedDocumentJob::class,
        fn (ProcessUploadedDocumentJob $job): bool => $job->uploadedDocumentId === $document->id
            && $job->expectedPath === $document->path
            && $job->pipelineId === $pipeline->id
            && $job->queue === 'ocr',
    );
});

it('queues the transcription job with the recording it has to transcribe', function (): void {
    $recording = startableRecording();

    $pipeline = $this->start->handle($recording, PipelineType::AudioTranscription, $this->owner);

    Queue::assertPushed(
        ProcessAudioRecordingJob::class,
        fn (ProcessAudioRecordingJob $job): bool => $job->audioRecordingId === $recording->id
            && $job->expectedPath === $recording->path
            && $job->pipelineId === $pipeline->id
            && $job->queue === 'transcription',
    );
});

it('queues the export job, which carries only the run it belongs to', function (): void {
    $export = startableExport();

    $pipeline = $this->start->handle($export, PipelineType::DossierExport, $this->owner);

    Queue::assertPushed(
        GenerateDossierExportJob::class,
        fn (GenerateDossierExportJob $job): bool => $job->pipelineId === $pipeline->id
            && $job->queue === 'dossier-export',
    );
});

it('dispatches after commit for every type, so a rolled back subject queues nothing', function (
    PipelineType $type,
    string $jobClass,
    Closure $subject,
): void {
    $this->start->handle($subject(), $type, $this->owner);

    // Callers invoke this inside the transaction that creates the subject, so a
    // worker picking the job up pre-commit would find neither row. Queue::fake()
    // records pushes immediately, hence asserting the flag rather than the timing.
    Queue::assertPushed($jobClass, fn (object $job): bool => $job->afterCommit === true);
})->with([
    'ocr' => [PipelineType::OcrDocument, ProcessUploadedDocumentJob::class, startableDocument(...)],
    'transcription' => [PipelineType::AudioTranscription, ProcessAudioRecordingJob::class, startableRecording(...)],
    'export' => [PipelineType::DossierExport, GenerateDossierExportJob::class, startableExport(...)],
]);

it('refuses a subject that does not belong to the pipeline type', function (): void {
    $document = startableDocument();

    expect(fn (): Pipeline => $this->start->handle($document, PipelineType::DossierExport))
        ->toThrow(RuntimeException::class);

    // The guard runs before anything is written, so no half-open run is left behind.
    expect(Pipeline::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('refuses a type whose owning domain forgot to register a runner', function (): void {
    expect(fn (): object => new PipelineRunnerRegistry()->for(PipelineType::OcrDocument))
        ->toThrow(RuntimeException::class, 'No runner registered for pipeline type [ocr_document].');
});

function startableDossier(): Dossier
{
    return Dossier::factory()->create([
        'tenant_id' => test()->tenant->id,
        'client_id' => Client::factory()->create(['tenant_id' => test()->tenant->id])->id,
    ]);
}

function startableDocument(): UploadedDocument
{
    $documentRequest = DocumentRequest::factory()->create([
        'tenant_id' => test()->tenant->id,
        'dossier_id' => startableDossier()->id,
    ]);

    return UploadedDocument::factory()->create([
        'tenant_id' => test()->tenant->id,
        'document_request_id' => $documentRequest->id,
    ]);
}

function startableRecording(): AudioRecording
{
    return AudioRecording::factory()->create([
        'tenant_id' => test()->tenant->id,
        'dossier_id' => startableDossier()->id,
    ]);
}

function startableExport(): DossierExport
{
    return DossierExport::factory()->create([
        'tenant_id' => test()->tenant->id,
        'dossier_id' => startableDossier()->id,
        'requested_by' => test()->owner->id,
    ]);
}
