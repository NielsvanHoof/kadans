<?php

declare(strict_types=1);

use App\Clients\Models\Client;
use App\DocumentProcessing\Actions\Ocr\CompleteTextractExtractionAction;
use App\DocumentProcessing\Contracts\Ocr\DocumentOcr;
use App\DocumentProcessing\Contracts\Ocr\DocumentOverviewNormalizer;
use App\DocumentProcessing\Enums\DocumentProcessingStatus;
use App\DocumentProcessing\Jobs\ProcessUploadedDocumentJob;
use App\Dossiers\Models\DocumentRequest;
use App\Dossiers\Models\Dossier;
use App\Dossiers\Models\UploadedDocument;
use App\Pipelines\Actions\CreatePipelineAction;
use App\Pipelines\Enums\PipelineStatus;
use App\Pipelines\Enums\PipelineStepKey;
use App\Pipelines\Enums\PipelineStepStatus;
use App\Pipelines\Enums\PipelineType;
use App\Tenancy\Models\Tenant;
use Aws\Textract\TextractClient;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('local');
    config(['ocr.driver' => 'textract']);
});

test('deferred OCR job advances the pipeline through provider processing', function (): void {
    ['tenant' => $tenant] = provisionWorkspace();

    $uploaded = uploadedDocumentForOcrPipeline($tenant);
    $pipeline = resolve(CreatePipelineAction::class)->handle($uploaded, PipelineType::OcrDocument);

    $ocr = Mockery::mock(DocumentOcr::class);
    $ocr->shouldReceive('start')->once()->andReturnUsing(function (UploadedDocument $document): void {
        $document->forceFill([
            'textract_job_id' => 'textract-deferred-1',
            'processing_status' => DocumentProcessingStatus::Processing,
        ])->save();
    });

    // Exercise Container::call like the queue worker — catches Laravel 13 nullable defaults.
    $job = new ProcessUploadedDocumentJob($uploaded->id, $uploaded->path, $pipeline->id);
    app()->instance(DocumentOcr::class, $ocr);
    app()->call($job->handle(...));

    $pipeline->refresh();
    $providerStep = $pipeline->steps()->where('key', PipelineStepKey::ProviderProcessing->value)->firstOrFail();

    expect($pipeline->status)->toBe(PipelineStatus::Running)
        ->and($pipeline->current_stage)->toBe(PipelineStepKey::ProviderProcessing)
        ->and($pipeline->provider)->toBe('textract')
        ->and($pipeline->provider_job_id)->toBe('textract-deferred-1')
        ->and($providerStep->status)->toBe(PipelineStepStatus::Running)
        ->and($providerStep->attempts)->toBe(1);
});

test('textract completion heals a pending OCR pipeline when the document is already done', function (): void {
    ['tenant' => $tenant] = provisionWorkspace();

    $uploaded = uploadedDocumentForOcrPipeline($tenant, [
        'processing_status' => DocumentProcessingStatus::Completed,
        'textract_job_id' => 'job-already-done',
        'extracted_text' => '{"fields":[]}',
        'processing_finished_at' => now(),
    ]);
    $pipeline = resolve(CreatePipelineAction::class)->handle($uploaded, PipelineType::OcrDocument);

    $overview = Mockery::mock(DocumentOverviewNormalizer::class);
    $overview->shouldNotReceive('describe');
    $this->app->instance(DocumentOverviewNormalizer::class, $overview);

    $textract = Mockery::mock(TextractClient::class);
    $textract->shouldNotReceive('getDocumentAnalysis');
    $this->app->instance(TextractClient::class, $textract);

    expect(resolve(CompleteTextractExtractionAction::class)->handle([
        'JobId' => 'job-already-done',
        'Status' => 'SUCCEEDED',
        'API' => 'StartDocumentAnalysis',
    ]))->toBeTrue();

    $pipeline->refresh();

    expect($pipeline->status)->toBe(PipelineStatus::Completed)
        ->and($pipeline->provider_job_id)->toBe('job-already-done')
        ->and($pipeline->steps()->pluck('status')->all())
        ->toBe([
            PipelineStepStatus::Completed,
            PipelineStepStatus::Completed,
            PipelineStepStatus::Completed,
        ]);
});

/**
 * @param  array<string, mixed>  $overrides
 */
function uploadedDocumentForOcrPipeline(Tenant $tenant, array $overrides = []): UploadedDocument
{
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
