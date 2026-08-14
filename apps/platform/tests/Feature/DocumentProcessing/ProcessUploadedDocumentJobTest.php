<?php

declare(strict_types=1);

use App\Audit\Actions\LogAuditActivityAction;
use App\Audit\Enums\AuditEvent;
use App\Audit\Models\Activity;
use App\Clients\Models\Client;
use App\DocumentProcessing\Contracts\Ocr\StartsDocumentOcr;
use App\DocumentProcessing\Enums\DocumentProcessingStatus;
use App\DocumentProcessing\Jobs\ProcessUploadedDocumentJob;
use App\DocumentProcessing\Services\Ocr\OcrOrchestrator;
use App\DocumentProcessing\Transitions\UploadedDocumentProcessingTransitions;
use App\Dossiers\Models\DocumentRequest;
use App\Dossiers\Models\Dossier;
use App\Dossiers\Models\UploadedDocument;
use App\Pipelines\Support\PipelineState;
use App\Tenancy\Models\Tenant;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

covers(ProcessUploadedDocumentJob::class);

beforeEach(function (): void {
    Storage::fake('local');
    config(['ocr.driver' => 'mock']);
});

test('processing job extracts mock text and marks the document completed', function (): void {
    $uploaded = createUploadedDocumentForProcessingTest();

    new ProcessUploadedDocumentJob($uploaded->id, $uploaded->path)->handle(
        resolve(OcrOrchestrator::class),
        resolve(LogAuditActivityAction::class),
        resolve(PipelineState::class),
        resolve(UploadedDocumentProcessingTransitions::class),
    );

    $uploaded->refresh();

    expect($uploaded->processing_status)->toBe(DocumentProcessingStatus::Completed)
        ->and($uploaded->extracted_text)->toContain('fields')
        ->and($uploaded->extracted_text)->toContain($uploaded->original_filename)
        ->and($uploaded->processing_error)->toBeNull()
        ->and($uploaded->processing_finished_at)->not->toBeNull()
        ->and(Activity::query()
            ->where('event', AuditEvent::DocumentProcessingStarted->value)
            ->where('subject_id', $uploaded->id)
            ->exists())
        ->toBeTrue()
        ->and(Activity::query()
            ->where('event', AuditEvent::DocumentProcessingCompleted->value)
            ->where('subject_id', $uploaded->id)
            ->exists())
        ->toBeTrue();
});

test('processing job is idempotent when the document is already completed', function (): void {
    $uploaded = createUploadedDocumentForProcessingTest([
        'processing_status' => DocumentProcessingStatus::Completed,
        'extracted_text' => 'Already extracted',
        'processing_finished_at' => now(),
    ]);

    new ProcessUploadedDocumentJob($uploaded->id, $uploaded->path)->handle(
        resolve(OcrOrchestrator::class),
        resolve(LogAuditActivityAction::class),
        resolve(PipelineState::class),
        resolve(UploadedDocumentProcessingTransitions::class),
    );

    $uploaded->refresh();

    expect($uploaded->extracted_text)->toBe('Already extracted')
        ->and($uploaded->processing_status)->toBe(DocumentProcessingStatus::Completed)
        ->and(Activity::query()
            ->where('event', AuditEvent::DocumentProcessingStarted->value)
            ->where('subject_id', $uploaded->id)
            ->exists())
        ->toBeFalse();
});

test('processing job does not restart an active external OCR job', function (): void {
    $uploaded = createUploadedDocumentForProcessingTest([
        'processing_status' => DocumentProcessingStatus::Processing,
        'textract_job_id' => 'active-textract-job',
        'processing_started_at' => now(),
    ]);

    $ocr = Mockery::mock(StartsDocumentOcr::class);
    $ocr->shouldNotReceive('start');

    new ProcessUploadedDocumentJob($uploaded->id, $uploaded->path)->handle(
        new OcrOrchestrator($ocr, resolve(LogAuditActivityAction::class)),
        resolve(LogAuditActivityAction::class),
        resolve(PipelineState::class),
        resolve(UploadedDocumentProcessingTransitions::class),
    );

    $uploaded->refresh();

    expect($uploaded->processing_status)->toBe(DocumentProcessingStatus::Processing)
        ->and($uploaded->textract_job_id)->toBe('active-textract-job')
        ->and(Activity::query()
            ->where('event', AuditEvent::DocumentProcessingStarted->value)
            ->where('subject_id', $uploaded->id)
            ->exists())
        ->toBeFalse();
});

test('processing job ignores an upload revision that has been replaced', function (): void {
    $uploaded = createUploadedDocumentForProcessingTest();
    $staleJob = new ProcessUploadedDocumentJob($uploaded->id, $uploaded->path);

    $uploaded->forceFill(['path' => 'replacement.pdf'])->save();

    $ocr = Mockery::mock(StartsDocumentOcr::class);
    $ocr->shouldNotReceive('start');

    $staleJob->handle(
        new OcrOrchestrator($ocr, resolve(LogAuditActivityAction::class)),
        resolve(LogAuditActivityAction::class),
        resolve(PipelineState::class),
        resolve(UploadedDocumentProcessingTransitions::class),
    );

    $uploaded->refresh();

    expect($uploaded->processing_status)->toBe(DocumentProcessingStatus::Pending)
        ->and($uploaded->path)->toBe('replacement.pdf');
});

test('processing audit events stay tenant-scoped', function (): void {
    ['tenant' => $tenantA] = provisionWorkspace('Firm A');
    ['tenant' => $tenantB] = provisionWorkspace('Firm B');

    $uploadedA = createUploadedDocumentForProcessingTest(tenant: $tenantA);

    new ProcessUploadedDocumentJob($uploadedA->id, $uploadedA->path)->handle(
        resolve(OcrOrchestrator::class),
        resolve(LogAuditActivityAction::class),
        resolve(PipelineState::class),
        resolve(UploadedDocumentProcessingTransitions::class),
    );

    expect(Activity::query()
        ->forTenant($tenantB)
        ->where('event', AuditEvent::DocumentProcessingCompleted->value)
        ->where('subject_id', $uploadedA->id)
        ->count())->toBe(0)
        ->and(Activity::query()
            ->forTenant($tenantA)
            ->where('event', AuditEvent::DocumentProcessingCompleted->value)
            ->where('subject_id', $uploadedA->id)
            ->count())
        ->toBe(1);
});

/**
 * The audit trail is allowed to name the file and does; the application log is
 * not, because retention:purge-legal and AnonymizeDataSubjectAction never reach
 * it, so a filename logged here could not be erased on request.
 *
 * tests/Unit/DocumentFilenameLoggingTest.php enforces this by scanning source.
 * This test proves it end to end on a real run of the OCR path, which is what
 * catches a filename arriving through an interpolated message or a collaborator
 * rather than a literal context key.
 */
test('a full OCR run leaks no client filename into the application log', function (): void {
    $uploaded = createUploadedDocumentForProcessingTest([
        'original_filename' => 'Loonstrook_J_de_Vries_maart.pdf',
    ]);

    $records = [];

    Log::listen(function (MessageLogged $message) use (&$records): void {
        $records[] = $message->message.' '.json_encode($message->context);
    });

    new ProcessUploadedDocumentJob($uploaded->id, $uploaded->path)->handle(
        resolve(OcrOrchestrator::class),
        resolve(LogAuditActivityAction::class),
        resolve(PipelineState::class),
        resolve(UploadedDocumentProcessingTransitions::class),
    );

    // The run has to have logged something, or the assertion below is empty.
    expect($records)->not->toBeEmpty();

    foreach ($records as $record) {
        expect($record)->not->toContain('Loonstrook_J_de_Vries_maart.pdf')
            ->and($record)->not->toContain('de_Vries');
    }

    // And the trail that *is* purged still carries it.
    expect(Activity::query()
        ->where('event', AuditEvent::DocumentProcessingStarted->value)
        ->where('subject_id', $uploaded->id)
        ->value('properties'))
        ->toContain('Loonstrook_J_de_Vries_maart.pdf');
});

/**
 * @param  array<string, mixed>  $overrides
 */
function createUploadedDocumentForProcessingTest(
    array $overrides = [],
    ?Tenant $tenant = null,
): UploadedDocument {
    // When a tenant is passed in, switch context; otherwise provision a workspace.
    $tenant ??= provisionWorkspace()['tenant'];
    actingInWorkspace($tenant);

    $client = Client::factory()->create(['tenant_id' => $tenant->id]);
    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
    ]);
    $documentRequest = DocumentRequest::factory()->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $dossier->id,
    ]);

    $path = 'tenants/'.$tenant->id.'/dossiers/'.$dossier->id.'/file.pdf';
    Storage::disk('local')->put($path, 'fake-pdf-bytes');

    return UploadedDocument::factory()->create([
        'tenant_id' => $tenant->id,
        'document_request_id' => $documentRequest->id,
        'disk' => 'local',
        'path' => $path,
        'original_filename' => 'statement.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 2048,
        'processing_status' => DocumentProcessingStatus::Pending,
        ...$overrides,
    ]);
}
