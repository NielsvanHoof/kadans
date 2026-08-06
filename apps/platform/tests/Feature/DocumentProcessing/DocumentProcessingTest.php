<?php

declare(strict_types=1);

use App\Clients\Models\Client;
use App\DocumentProcessing\Enums\DocumentProcessingStatus;
use App\DocumentProcessing\Jobs\ProcessUploadedDocumentJob;
use App\Dossiers\Actions\DocumentRequests\UploadDocumentForRequestAction;
use App\Dossiers\Enums\DocumentRequestStatus;
use App\Dossiers\Models\DocumentRequest;
use App\Dossiers\Models\Dossier;
use App\Dossiers\Models\UploadedDocument;
use App\Pipelines\Models\Pipeline;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    Storage::fake('local');
    config()->set('filesystems.default', 'local');
});

test('uploading a document dispatches the mock OCR processing job', function (): void {
    Queue::fake();

    ['tenant' => $tenant] = provisionWorkspace();

    $client = Client::factory()->create(['tenant_id' => $tenant->id]);
    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
    ]);
    $documentRequest = DocumentRequest::factory()->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $dossier->id,
        'status' => DocumentRequestStatus::Pending,
    ]);

    $uploaded = resolve(UploadDocumentForRequestAction::class)->handle(
        $documentRequest,
        UploadedFile::fake()->create('payslip.pdf', 100, 'application/pdf'),
    );

    expect($uploaded->processing_status)->toBe(DocumentProcessingStatus::Pending)
        ->and(Pipeline::query()
            ->where('pipelineable_type', (new UploadedDocument)->getMorphClass())
            ->where('pipelineable_id', $uploaded->id)
            ->sole()->type->value)
        ->toBe('ocr_document');

    Queue::assertPushed(ProcessUploadedDocumentJob::class, fn (ProcessUploadedDocumentJob $job): bool => $job->uploadedDocumentId === $uploaded->id
        && $job->pipelineId !== null);
});

test('staff dossier show includes processing fields for uploaded documents', function (): void {
    Queue::fake();

    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();

    $client = Client::factory()->create(['tenant_id' => $tenant->id]);
    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
    ]);
    $documentRequest = DocumentRequest::factory()->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $dossier->id,
        'status' => DocumentRequestStatus::Pending,
    ]);

    resolve(UploadDocumentForRequestAction::class)->handle(
        $documentRequest,
        UploadedFile::fake()->create('payslip.pdf', 100, 'application/pdf'),
    );

    $this->actingAs($owner)
        ->get(workspaceRoute('workspaces.dossiers.show', $tenant, [
            'dossier' => $dossier,
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('dossiers/show')
            ->has('dossier.document_requests.0.uploaded_documents.0', fn (Assert $document): Assert => $document
                ->where('original_filename', 'payslip.pdf')
                ->where('processing_status', DocumentProcessingStatus::Pending->value)
                ->missing('extracted_text')
                ->etc()
            )
        );
});
