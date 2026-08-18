<?php

declare(strict_types=1);

use App\Audit\Actions\LogAuditActivityAction;
use App\Audit\Enums\AuditEvent;
use App\Audit\Models\Activity;
use App\Clients\Models\Client;
use App\DocumentProcessing\Contracts\Ocr\DocumentOcr;
use App\DocumentProcessing\Enums\DocumentProcessingStatus;
use App\DocumentProcessing\Enums\OcrProcessingOutcome;
use App\DocumentProcessing\Services\Ocr\OcrOrchestrator;
use App\Dossiers\Models\DocumentRequest;
use App\Dossiers\Models\Dossier;
use App\Dossiers\Models\UploadedDocument;

beforeEach(function (): void {
    config(['ocr.driver' => 'mock']);
});

test('ocr orchestrator returns immediate outcome for synchronous drivers', function (): void {
    $uploaded = createUploadedDocumentForOcrOrchestratorTest();

    $outcome = resolve(OcrOrchestrator::class)->startProcessing($uploaded);

    $uploaded->refresh();

    expect($outcome)->toBe(OcrProcessingOutcome::Immediate)
        ->and($uploaded->processing_status)->toBe(DocumentProcessingStatus::Completed)
        ->and($uploaded->extracted_text)->toContain('fields')
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

test('ocr orchestrator returns deferred outcome when the driver leaves processing open', function (): void {
    $uploaded = createUploadedDocumentForOcrOrchestratorTest();

    $mockOcr = Mockery::mock(DocumentOcr::class);
    $mockOcr->shouldReceive('start')
        ->once()
        ->with(Mockery::on(fn (UploadedDocument $document): bool => $document->is($uploaded)));

    $orchestrator = new OcrOrchestrator($mockOcr, resolve(LogAuditActivityAction::class));

    $outcome = $orchestrator->startProcessing($uploaded);

    expect($outcome)->toBe(OcrProcessingOutcome::Deferred)
        ->and(Activity::query()
            ->where('event', AuditEvent::DocumentProcessingStarted->value)
            ->where('subject_id', $uploaded->id)
            ->exists())
        ->toBeTrue()
        ->and(Activity::query()
            ->where('event', AuditEvent::DocumentProcessingCompleted->value)
            ->where('subject_id', $uploaded->id)
            ->exists())
        ->toBeFalse();
});

/**
 * @param  array<string, mixed>  $attributes
 */
function createUploadedDocumentForOcrOrchestratorTest(array $attributes = []): UploadedDocument
{
    // provisionWorkspace sets the current tenant for scoped queries and audit logs.
    ['tenant' => $tenant] = provisionWorkspace();

    $client = Client::factory()->create(['tenant_id' => $tenant->id]);
    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
    ]);
    $documentRequest = DocumentRequest::factory()->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $dossier->id,
    ]);

    return UploadedDocument::factory()->create(array_merge([
        'tenant_id' => $tenant->id,
        'document_request_id' => $documentRequest->id,
        'processing_status' => DocumentProcessingStatus::Processing,
        'processing_started_at' => now(),
    ], $attributes));
}
