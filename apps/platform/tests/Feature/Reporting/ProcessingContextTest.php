<?php

declare(strict_types=1);

use App\Clients\Models\Client;
use App\Dossiers\Models\AudioRecording;
use App\Dossiers\Models\DocumentRequest;
use App\Dossiers\Models\Dossier;
use App\Dossiers\Models\DossierExport;
use App\Dossiers\Models\UploadedDocument;
use App\Pipelines\Enums\PipelineType;
use App\Pipelines\Models\Pipeline;
use App\Pipelines\Support\PipelineSubjectPresenterRegistry;
use App\Reporting\Queries\Dashboard\FetchDashboardProcessingHealthQuery;
use App\Reporting\Queries\Pipelines\FetchPipelineIndexQuery;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;

dataset('processing context surfaces', ['index' => false, 'dashboard' => true]);
dataset('processing context types', [
    'document scan' => PipelineType::OcrDocument,
    'transcription' => PipelineType::AudioTranscription,
    'export' => PipelineType::DossierExport,
]);

test('processing rows describe each subject with client and dossier context', function (bool $dashboard, PipelineType $type): void {
    ['tenant' => $tenant] = provisionWorkspace();
    $pipeline = processingContextPipeline($tenant, $type);
    $expectedType = match ($type) {
        PipelineType::OcrDocument => 'UploadedDocument',
        PipelineType::AudioTranscription => 'AudioRecording',
        PipelineType::DossierExport => 'DossierExport',
    };
    $expectedLabel = match ($type) {
        PipelineType::OcrDocument => 'invoice.pdf',
        PipelineType::AudioTranscription => 'client-call.m4a',
        PipelineType::DossierExport => "Dossier export #{$pipeline->pipelineable_id}",
    };
    $dossier = Dossier::query()->sole();

    $rows = processingContextRows($tenant, $dashboard);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['subject'])->toBe([
            'type' => $expectedType,
            'id' => $pipeline->pipelineable_id,
            'label' => $expectedLabel,
            'context' => 'Acme Client · Payroll 2026',
            'href' => workspaceRoute('workspaces.dossiers.show', $tenant, ['dossier' => $dossier->id]),
        ]);
})->with('processing context surfaces')->with('processing context types');

test('processing rows retain subject identity after the subject is deleted', function (bool $dashboard, PipelineType $type): void {
    ['tenant' => $tenant] = provisionWorkspace();
    $pipeline = processingContextPipeline($tenant, $type);
    $pipeline->pipelineable->delete();

    $rows = processingContextRows($tenant, $dashboard);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['subject'])->toMatchArray([
            'id' => $pipeline->pipelineable_id,
            'label' => null,
            'context' => null,
            'href' => null,
        ]);
})->with('processing context surfaces')->with('processing context types');

test('processing rows do not disclose another tenants subjects or runs', function (bool $dashboard, PipelineType $type): void {
    ['tenant' => $tenant] = provisionWorkspace();
    ['tenant' => $foreignTenant] = provisionWorkspace('Other firm');
    $foreignPipeline = processingContextPipeline($foreignTenant, $type);
    actingInWorkspace($tenant);
    $localPipeline = Pipeline::factory()->failed()->create([
        'tenant_id' => $tenant->id,
        'type' => $type,
        'pipelineable_type' => $foreignPipeline->pipelineable_type,
        'pipelineable_id' => $foreignPipeline->pipelineable_id,
    ]);

    $rows = processingContextRows($tenant, $dashboard);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['id'])->toBe($localPipeline->id)
        ->and($rows[0]['subject'])->toMatchArray([
            'label' => null,
            'context' => null,
            'href' => null,
        ]);
})->with('processing context surfaces')->with('processing context types');

test('processing context query counts stay constant as each subject type gains rows', function (bool $dashboard, PipelineType $type): void {
    ['tenant' => $tenant] = provisionWorkspace();
    processingContextPipeline($tenant, $type);
    $singleRowQueries = processingContextQueryCount($tenant, $dashboard);
    processingContextPipeline($tenant, $type);
    processingContextPipeline($tenant, $type);

    $multipleRowQueries = processingContextQueryCount($tenant, $dashboard);

    expect(processingContextRows($tenant, $dashboard))->toHaveCount(3)
        ->and($multipleRowQueries)->toBe($singleRowQueries);
})->with('processing context surfaces')->with('processing context types');

test('an unregistered presenter leaves processing rows readable without a description', function (bool $dashboard): void {
    ['tenant' => $tenant] = provisionWorkspace();
    $pipeline = processingContextPipeline($tenant, PipelineType::AudioTranscription);
    $this->app->instance(PipelineSubjectPresenterRegistry::class, new PipelineSubjectPresenterRegistry());

    $rows = processingContextRows($tenant, $dashboard);

    expect($rows[0]['subject'])->toBe([
        'type' => 'AudioRecording',
        'id' => $pipeline->pipelineable_id,
        'label' => null,
        'context' => null,
        'href' => null,
    ]);
})->with('processing context surfaces');

test('processing context falls back to the dossier title when its client is unavailable', function (bool $dashboard): void {
    ['tenant' => $tenant] = provisionWorkspace();
    processingContextPipeline($tenant, PipelineType::AudioTranscription);
    Client::query()->sole()->delete();

    $rows = processingContextRows($tenant, $dashboard);

    expect($rows[0]['subject']['context'])->toBe('Payroll 2026');
})->with('processing context surfaces');

test('mixed process types are described together without per-row queries', function (bool $dashboard): void {
    ['tenant' => $tenant] = provisionWorkspace();
    foreach (PipelineType::cases() as $type) {
        processingContextPipeline($tenant, $type);
    }
    $initialQueries = processingContextQueryCount($tenant, $dashboard);
    processingContextPipeline($tenant, PipelineType::OcrDocument);
    processingContextPipeline($tenant, PipelineType::AudioTranscription);

    $queries = processingContextQueryCount($tenant, $dashboard);
    $rows = processingContextRows($tenant, $dashboard);

    expect($queries)->toBe($initialQueries)
        ->and($rows)->toHaveCount(5)
        ->and(array_column(array_column($rows, 'subject'), 'type'))
        ->toContain('UploadedDocument', 'AudioRecording', 'DossierExport');
})->with('processing context surfaces');

test('processing context is absent when the dossier is unavailable', function (bool $dashboard): void {
    ['tenant' => $tenant] = provisionWorkspace();
    processingContextPipeline($tenant, PipelineType::AudioTranscription);
    Dossier::query()->sole()->delete();

    $rows = processingContextRows($tenant, $dashboard);

    expect($rows[0]['subject']['label'])->toBe('client-call.m4a')
        ->and($rows[0]['subject']['context'])->toBeNull();
})->with('processing context surfaces');

/** @return list<array<string, mixed>> */
function processingContextRows(Tenant $tenant, bool $dashboard): array
{
    return $dashboard
        ? resolve(FetchDashboardProcessingHealthQuery::class)->handle($tenant)->toArray()['recent']
        : resolve(FetchPipelineIndexQuery::class)->handle()->items();
}

function processingContextQueryCount(Tenant $tenant, bool $dashboard): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();

    try {
        processingContextRows($tenant, $dashboard);

        return count(DB::getQueryLog());
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }
}

function processingContextPipeline(Tenant $tenant, PipelineType $type): Pipeline
{
    $client = Client::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Acme Client']);
    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
        'title' => 'Payroll 2026',
    ]);
    $subject = match ($type) {
        PipelineType::OcrDocument => UploadedDocument::factory()->create([
            'tenant_id' => $tenant->id,
            'document_request_id' => DocumentRequest::factory()->create([
                'tenant_id' => $tenant->id,
                'dossier_id' => $dossier->id,
            ])->id,
            'original_filename' => 'invoice.pdf',
        ]),
        PipelineType::AudioTranscription => AudioRecording::factory()->create([
            'tenant_id' => $tenant->id,
            'dossier_id' => $dossier->id,
            'original_filename' => 'client-call.m4a',
        ]),
        PipelineType::DossierExport => DossierExport::factory()->create([
            'tenant_id' => $tenant->id,
            'dossier_id' => $dossier->id,
        ]),
    };

    return Pipeline::factory()->failed()->for($subject, 'pipelineable')->create([
        'tenant_id' => $tenant->id,
        'type' => $type,
    ]);
}
