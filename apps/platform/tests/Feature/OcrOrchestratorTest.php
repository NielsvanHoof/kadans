<?php

declare(strict_types=1);

use App\Audit\Actions\LogAuditActivityAction;
use App\Audit\Enums\AuditEvent;
use App\Audit\Models\Activity;
use App\Clients\Models\Client;
use App\DocumentProcessing\Contracts\Ocr\StartsDocumentOcr;
use App\DocumentProcessing\Enums\DocumentProcessingStatus;
use App\DocumentProcessing\Enums\OcrProcessingOutcome;
use App\DocumentProcessing\Services\Ocr\OcrOrchestrator;
use App\Dossiers\Models\DocumentRequest;
use App\Dossiers\Models\Dossier;
use App\Dossiers\Models\UploadedDocument;
use App\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['ocr.driver' => 'mock']);
});

test('ocr orchestrator returns immediate outcome for synchronous drivers', function () {
    $uploaded = createUploadedDocumentForOcrOrchestratorTest();

    $outcome = app(OcrOrchestrator::class)->startProcessing($uploaded);

    $uploaded->refresh();

    expect($outcome)->toBe(OcrProcessingOutcome::Immediate)
        ->and($uploaded->processing_status)->toBe(DocumentProcessingStatus::Completed)
        ->and($uploaded->extracted_text)->toContain('fields');

    expect(Activity::query()
        ->where('event', AuditEvent::DocumentProcessingStarted->value)
        ->where('subject_id', $uploaded->id)
        ->exists())->toBeTrue();

    expect(Activity::query()
        ->where('event', AuditEvent::DocumentProcessingCompleted->value)
        ->where('subject_id', $uploaded->id)
        ->exists())->toBeTrue();
});

test('ocr orchestrator returns deferred outcome when the driver leaves processing open', function () {
    $uploaded = createUploadedDocumentForOcrOrchestratorTest();

    $mockOcr = Mockery::mock(StartsDocumentOcr::class);
    $mockOcr->shouldReceive('start')
        ->once()
        ->with(Mockery::on(fn (UploadedDocument $document): bool => $document->is($uploaded)));

    $orchestrator = new OcrOrchestrator($mockOcr, app(LogAuditActivityAction::class));

    $outcome = $orchestrator->startProcessing($uploaded);

    expect($outcome)->toBe(OcrProcessingOutcome::Deferred);

    expect(Activity::query()
        ->where('event', AuditEvent::DocumentProcessingStarted->value)
        ->where('subject_id', $uploaded->id)
        ->exists())->toBeTrue();

    expect(Activity::query()
        ->where('event', AuditEvent::DocumentProcessingCompleted->value)
        ->where('subject_id', $uploaded->id)
        ->exists())->toBeFalse();
});

/**
 * @param  array<string, mixed>  $attributes
 */
function createUploadedDocumentForOcrOrchestratorTest(array $attributes = []): UploadedDocument
{
    $tenant = Tenant::factory()->create();
    $tenant->makeCurrent();

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
