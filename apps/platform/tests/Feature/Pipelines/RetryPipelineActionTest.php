<?php

declare(strict_types=1);

use App\AudioProcessing\Jobs\ProcessAudioRecordingJob;
use App\Audit\Actions\LogAuditActivityAction;
use App\Audit\Enums\AuditEvent;
use App\Audit\Models\Activity;
use App\Clients\Models\Client;
use App\DocumentProcessing\Enums\DocumentProcessingStatus;
use App\DocumentProcessing\Enums\TranscriptionStatus;
use App\DocumentProcessing\Jobs\ProcessUploadedDocumentJob;
use App\DocumentProcessing\Services\Ocr\OcrOrchestrator;
use App\DocumentProcessing\Transitions\UploadedDocumentProcessingTransitions;
use App\Dossiers\Enums\DossierExportStatus;
use App\Dossiers\Jobs\GenerateDossierExportJob;
use App\Dossiers\Models\AudioRecording;
use App\Dossiers\Models\DocumentRequest;
use App\Dossiers\Models\Dossier;
use App\Dossiers\Models\DossierExport;
use App\Dossiers\Models\UploadedDocument;
use App\Pipelines\Actions\PipelineStateAction;
use App\Pipelines\Actions\RetryPipelineAction;
use App\Pipelines\Enums\PipelineStatus;
use App\Pipelines\Enums\PipelineType;
use App\Pipelines\Events\PipelineUpdated;
use App\Pipelines\Models\Pipeline;
use App\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/*
|--------------------------------------------------------------------------
| RetryPipelineAction
|--------------------------------------------------------------------------
|
| Retry has to do two things that are easy to get silently wrong: dispatch the
| job that matches the pipeline type, and reset the subject record first. Both
| processing jobs skip their work without erroring when the subject is still
| `processing` with a provider job in flight, so a retry that forgets the reset
| reports success and then does nothing at all.
|
*/

beforeEach(function (): void {
    Storage::fake('local');
    config(['ocr.driver' => 'mock']);

    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();
    $this->owner = $owner;
    $this->tenant = $tenant;
    $this->retry = resolve(RetryPipelineAction::class);
});

describe('dispatching', function (): void {
    it('queues the job that matches the pipeline type', function (
        PipelineType $type,
        string $expectedJob,
    ): void {
        Queue::fake();
        $pipeline = failedPipelineFor(retrySubjectFor($type, $this->tenant), $type);

        $this->retry->handle($pipeline, $this->owner);

        Queue::assertPushed($expectedJob, fn (object $job): bool => $job->pipelineId === $pipeline->id);
    })->with([
        'ocr document' => [PipelineType::OcrDocument, ProcessUploadedDocumentJob::class],
        'audio transcription' => [PipelineType::AudioTranscription, ProcessAudioRecordingJob::class],
        'dossier export' => [PipelineType::DossierExport, GenerateDossierExportJob::class],
    ]);

    it('passes the current subject id and path to the processing job', function (): void {
        Queue::fake();
        $document = retryOcrDocument($this->tenant);
        $pipeline = failedPipelineFor($document, PipelineType::OcrDocument);

        $this->retry->handle($pipeline, $this->owner);

        Queue::assertPushed(
            ProcessUploadedDocumentJob::class,
            fn (ProcessUploadedDocumentJob $job): bool => $job->uploadedDocumentId === $document->id
                && $job->expectedPath === $document->path,
        );
    });
});

describe('subject reset', function (): void {
    it('reopens an uploaded document that was mid-flight', function (): void {
        Queue::fake();
        $document = retryOcrDocument($this->tenant);
        $document->forceFill([
            'processing_status' => DocumentProcessingStatus::Processing,
            'processing_error' => 'Textract timed out',
            'extracted_text' => 'stale text',
            'textract_job_id' => 'stale-textract-job',
            'processing_started_at' => now()->subMinutes(30),
            'processing_finished_at' => now()->subMinutes(20),
        ])->saveQuietly();
        $pipeline = failedPipelineFor($document, PipelineType::OcrDocument);

        $this->retry->handle($pipeline, $this->owner);

        expect($document->fresh())
            ->processing_status->toBe(DocumentProcessingStatus::Pending)
            ->processing_error->toBeNull()
            ->extracted_text->toBeNull()
            ->textract_job_id->toBeNull()
            ->processing_started_at->toBeNull()
            ->processing_finished_at->toBeNull();
    });

    it('reopens an audio recording that was mid-flight', function (): void {
        Queue::fake();
        $recording = retryAudioRecording($this->tenant);
        $recording->forceFill([
            'transcription_status' => TranscriptionStatus::Processing,
            'transcription_error' => 'Transcribe timed out',
            'transcript' => 'stale transcript',
            'segments' => [['text' => 'x', 'speaker' => 'a', 'start_seconds' => 0.0, 'end_seconds' => 1.0]],
            'transcribe_job_id' => 'stale-transcribe-job',
            'transcription_started_at' => now()->subMinutes(30),
            'transcription_finished_at' => now()->subMinutes(20),
        ])->saveQuietly();
        $pipeline = failedPipelineFor($recording, PipelineType::AudioTranscription);

        $this->retry->handle($pipeline, $this->owner);

        expect($recording->fresh())
            ->transcription_status->toBe(TranscriptionStatus::Pending)
            ->transcription_error->toBeNull()
            ->transcript->toBeNull()
            ->segments->toBeNull()
            ->transcribe_job_id->toBeNull()
            ->transcription_started_at->toBeNull()
            ->transcription_finished_at->toBeNull();
    });

    it('reopens a failed dossier export', function (): void {
        Queue::fake();
        $export = retryDossierExport($this->tenant);
        $export->forceFill([
            'status' => DossierExportStatus::Failed,
            'error' => 'Archive could not be written',
            'expires_at' => now()->subMinute(),
            'completed_at' => now()->subMinutes(5),
            'failed_at' => now()->subMinutes(5),
        ])->saveQuietly();
        $pipeline = failedPipelineFor($export, PipelineType::DossierExport);

        $this->retry->handle($pipeline, $this->owner);

        expect($export->fresh())
            ->status->toBe(DossierExportStatus::Pending)
            ->error->toBeNull()
            ->expires_at->toBeNull()
            ->completed_at->toBeNull()
            ->failed_at->toBeNull();
    });
});

describe('regression: the run actually restarts', function (): void {
    /*
     * The guard this protects lives in ProcessUploadedDocumentJob::claimForProcessing():
     * a document that is `processing` with a non-null textract_job_id is skipped
     * without error. That is precisely the state pipelines:monitor leaves behind
     * when it marks a run stuck, so this asserts on real work done rather than on
     * columns the action itself wrote.
     */
    it('lets the job process a document that a stuck run left claimed', function (): void {
        $document = retryOcrDocument($this->tenant);
        $document->forceFill([
            'processing_status' => DocumentProcessingStatus::Processing,
            'textract_job_id' => 'abandoned-textract-job',
            'processing_started_at' => now()->subHour(),
        ])->saveQuietly();
        $pipeline = failedPipelineFor($document, PipelineType::OcrDocument, PipelineStatus::Stuck);

        Queue::fake();
        $this->retry->handle($pipeline, $this->owner);

        new ProcessUploadedDocumentJob($document->id, $document->path, $pipeline->id)->handle(
            resolve(OcrOrchestrator::class),
            resolve(LogAuditActivityAction::class),
            resolve(PipelineStateAction::class),
            resolve(UploadedDocumentProcessingTransitions::class),
        );

        expect($document->fresh())
            ->processing_status->toBe(DocumentProcessingStatus::Completed)
            ->extracted_text->not->toBeNull();
    });

    it('would be skipped without the reset', function (): void {
        // Same starting state, but no retry: proves the assertion above is real.
        $document = retryOcrDocument($this->tenant);
        $document->forceFill([
            'processing_status' => DocumentProcessingStatus::Processing,
            'textract_job_id' => 'abandoned-textract-job',
            'processing_started_at' => now()->subHour(),
        ])->saveQuietly();

        new ProcessUploadedDocumentJob($document->id, $document->path)->handle(
            resolve(OcrOrchestrator::class),
            resolve(LogAuditActivityAction::class),
            resolve(PipelineStateAction::class),
            resolve(UploadedDocumentProcessingTransitions::class),
        );

        expect($document->fresh())
            ->processing_status->toBe(DocumentProcessingStatus::Processing)
            ->extracted_text->toBeNull();
    });
});

describe('bookkeeping', function (): void {
    it('records who retried the run and what it looked like before', function (): void {
        Queue::fake();
        $pipeline = failedPipelineFor(retryOcrDocument($this->tenant), PipelineType::OcrDocument);

        $this->retry->handle($pipeline, $this->owner);

        $activity = Activity::query()
            ->where('event', AuditEvent::PipelineRetried->value)
            ->where('subject_id', $pipeline->id)
            ->sole();

        expect($activity->causer_id)->toBe($this->owner->id)
            ->and($activity->getProperty('pipeline_type'))->toBe(PipelineType::OcrDocument->value)
            ->and($activity->getProperty('previous_status'))->toBe(PipelineStatus::Failed->value)
            ->and($activity->getProperty('attempt_number'))->toBe(2);
    });

    it('broadcasts the reopened run to the workspace', function (): void {
        Queue::fake();
        Event::fake([PipelineUpdated::class]);
        $pipeline = failedPipelineFor(retryOcrDocument($this->tenant), PipelineType::OcrDocument);

        $this->retry->handle($pipeline, $this->owner);

        Event::assertDispatched(fn (PipelineUpdated $event): bool => $event->pipelineId === $pipeline->id
            && $event->status === PipelineStatus::Pending->value);
    });
});

describe('guards', function (): void {
    it('refuses a run that is not failed or stuck and queues nothing', function (PipelineStatus $status): void {
        Queue::fake();
        $pipeline = failedPipelineFor(retryOcrDocument($this->tenant), PipelineType::OcrDocument, $status);

        expect(fn () => $this->retry->handle($pipeline, $this->owner))
            ->toThrow(ValidationException::class);

        Queue::assertNothingPushed();
        expect($pipeline->fresh()->attempt_number)->toBe(1);
    })->with([
        'pending' => [PipelineStatus::Pending],
        'running' => [PipelineStatus::Running],
        'completed' => [PipelineStatus::Completed],
        'cancelled' => [PipelineStatus::Cancelled],
    ]);

    it('refuses a run whose subject has been deleted', function (): void {
        Queue::fake();
        $document = retryOcrDocument($this->tenant);
        $pipeline = failedPipelineFor($document, PipelineType::OcrDocument);
        $document->forceDelete();

        expect(fn () => $this->retry->handle($pipeline->fresh(), $this->owner))
            ->toThrow(ValidationException::class);

        Queue::assertNothingPushed();
    });
});

function failedPipelineFor(
    Model $subject,
    PipelineType $type,
    PipelineStatus $status = PipelineStatus::Failed,
): Pipeline {
    $pipeline = Pipeline::factory()->failed()->create([
        'tenant_id' => test()->tenant->id,
        'type' => $type,
        'status' => $status,
        'pipelineable_type' => $subject->getMorphClass(),
        'pipelineable_id' => $subject->getKey(),
    ]);

    foreach ($type->stepKeys() as $position => $key) {
        $pipeline->steps()->create([
            'tenant_id' => test()->tenant->id,
            'key' => $key,
            'position' => $position,
        ]);
    }

    return $pipeline->fresh();
}

function retrySubjectFor(PipelineType $type, Tenant $tenant): Model
{
    return match ($type) {
        PipelineType::OcrDocument => retryOcrDocument($tenant),
        PipelineType::AudioTranscription => retryAudioRecording($tenant),
        PipelineType::DossierExport => retryDossierExport($tenant),
    };
}

function retryOcrDocument(Tenant $tenant): UploadedDocument
{
    $documentRequest = DocumentRequest::factory()->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => retryDossier($tenant)->id,
    ]);

    $path = 'tenants/'.$tenant->id.'/statement.pdf';
    Storage::disk('local')->put($path, 'fake-pdf-bytes');

    return UploadedDocument::factory()->create([
        'tenant_id' => $tenant->id,
        'document_request_id' => $documentRequest->id,
        'disk' => 'local',
        'path' => $path,
        'original_filename' => 'statement.pdf',
        'processing_status' => DocumentProcessingStatus::Pending,
    ]);
}

function retryAudioRecording(Tenant $tenant): AudioRecording
{
    return AudioRecording::factory()->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => retryDossier($tenant)->id,
    ]);
}

function retryDossierExport(Tenant $tenant): DossierExport
{
    return DossierExport::factory()->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => retryDossier($tenant)->id,
    ]);
}

function retryDossier(Tenant $tenant): Dossier
{
    $client = Client::factory()->create(['tenant_id' => $tenant->id]);

    return Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
    ]);
}
