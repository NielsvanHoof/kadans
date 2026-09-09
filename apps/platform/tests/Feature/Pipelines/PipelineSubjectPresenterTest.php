<?php

declare(strict_types=1);

use App\Clients\Models\Client;
use App\Dossiers\Models\AudioRecording;
use App\Dossiers\Models\DocumentRequest;
use App\Dossiers\Models\Dossier;
use App\Dossiers\Models\DossierExport;
use App\Dossiers\Models\UploadedDocument;
use App\Pipelines\Actions\CreatePipelineAction;
use App\Pipelines\Enums\PipelineType;
use App\Pipelines\Events\PipelineUpdated;
use App\Pipelines\Support\PipelineSubjectPresenterRegistry;
use App\Reporting\Queries\Pipelines\FetchPipelineShowQuery;
use Illuminate\Support\Facades\Event;

test('every pipeline type has a subject presenter registered by its owning domain', function (): void {
    $registry = resolve(PipelineSubjectPresenterRegistry::class);

    foreach (PipelineType::cases() as $type) {
        expect($registry->for($type))->not->toBeNull("No subject presenter registered for [{$type->value}].");
    }
});

test('an audio transcription run describes its subject on the show page', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();
    $dossier = presenterDossier($tenant->id, 'Q3 audit file');

    $recording = AudioRecording::factory()->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $dossier->id,
        'original_filename' => 'client-call.m4a',
    ]);
    $pipeline = resolve(CreatePipelineAction::class)->handle($recording, PipelineType::AudioTranscription, $owner);

    ['pipeline' => $data] = resolve(FetchPipelineShowQuery::class)->handle($pipeline);

    expect($data['subject']['label'])->toBe('client-call.m4a')
        ->and($data['subject']['context'])->toBe('Q3 audit file')
        ->and($data['subject']['href'])->toContain("/dossiers/{$dossier->id}");
});

test('the show page describes uploaded document and dossier export subjects too', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();
    $dossier = presenterDossier($tenant->id, 'Payroll 2026');

    $request = DocumentRequest::factory()->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $dossier->id,
    ]);
    $document = UploadedDocument::factory()->create([
        'tenant_id' => $tenant->id,
        'document_request_id' => $request->id,
        'original_filename' => 'invoice.pdf',
    ]);
    $export = DossierExport::factory()->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $dossier->id,
    ]);

    $ocr = resolve(CreatePipelineAction::class)->handle($document, PipelineType::OcrDocument, $owner);
    $exportRun = resolve(CreatePipelineAction::class)->handle($export, PipelineType::DossierExport, $owner);

    $query = resolve(FetchPipelineShowQuery::class);

    expect($query->handle($ocr)['pipeline']['subject'])
        ->label->toBe('invoice.pdf')
        ->context->toBe('Payroll 2026')
        ->and($query->handle($exportRun)['pipeline']['subject'])
        ->label->toBe("Dossier export #{$export->id}")
        ->context->toBe('Payroll 2026');
});

test('the show page still renders when the subject has been purged', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();
    $dossier = presenterDossier($tenant->id);
    $recording = AudioRecording::factory()->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $dossier->id,
    ]);
    $pipeline = resolve(CreatePipelineAction::class)->handle($recording, PipelineType::AudioTranscription, $owner);

    $recording->forceDelete();

    ['pipeline' => $data] = resolve(FetchPipelineShowQuery::class)->handle($pipeline->fresh());

    expect($data['subject']['label'])->toBeNull()
        ->and($data['subject']['id'])->toBe($recording->id);
});

test('a pipeline broadcast carries the dossier resolved by the owning domain', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();
    $dossier = presenterDossier($tenant->id);
    $recording = AudioRecording::factory()->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $dossier->id,
    ]);

    Event::fake([PipelineUpdated::class]);

    resolve(CreatePipelineAction::class)->handle($recording, PipelineType::AudioTranscription, $owner);

    Event::assertDispatched(fn (PipelineUpdated $event): bool => $event->dossierId === $dossier->id);
});

function presenterDossier(int $tenantId, string $title = 'Test dossier'): Dossier
{
    $client = Client::factory()->create(['tenant_id' => $tenantId]);

    return Dossier::factory()->create([
        'tenant_id' => $tenantId,
        'client_id' => $client->id,
        'title' => $title,
    ]);
}
