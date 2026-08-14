<?php

declare(strict_types=1);

use App\Clients\Models\Client;
use App\Dossiers\Actions\GenerateDossierExportAction;
use App\Dossiers\Enums\DossierExportStatus;
use App\Dossiers\Models\DocumentRequest;
use App\Dossiers\Models\Dossier;
use App\Dossiers\Models\DossierExport;
use App\Dossiers\Models\UploadedDocument;
use App\Pipelines\Actions\CreatePipelineAction;
use App\Pipelines\Enums\PipelineType;
use App\Questionnaires\Enums\QuestionnaireItemType;
use Illuminate\Support\Facades\Storage;

covers(GenerateDossierExportAction::class);

beforeEach(function (): void {
    Storage::fake('local');
    config()->set('exports.disk', 'local');
});

/**
 * The archive was assembled with ZipArchive::addFromString(), which holds every
 * entry's bytes until close() — 400 MB of scans meant roughly 400 MB of RSS on a
 * queue worker carrying no memory hint. addFile() reads lazily at close()
 * instead, and files are staged with a streaming copy, so peak memory is one
 * stream buffer rather than one whole document.
 *
 * @return array{export: DossierExport, dossier: Dossier, pipelineId: int}
 */
function dossierExportFixture(int $documentCount, int $bytesPerDocument): array
{
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();

    $client = Client::factory()->create(['tenant_id' => $tenant->id]);
    $dossier = Dossier::factory()->create(['tenant_id' => $tenant->id, 'client_id' => $client->id]);

    $documentRequest = DocumentRequest::factory()->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $dossier->id,
        'type' => QuestionnaireItemType::File,
    ]);

    foreach (range(1, $documentCount) as $index) {
        $path = sprintf('tenants/%d/dossiers/%d/scan-%d.pdf', $tenant->id, $dossier->id, $index);
        Storage::disk('local')->put($path, str_repeat('x', $bytesPerDocument));

        UploadedDocument::factory()->create([
            'tenant_id' => $tenant->id,
            'document_request_id' => $documentRequest->id,
            'disk' => 'local',
            'path' => $path,
            'original_filename' => sprintf('scan-%d.pdf', $index),
            'size_bytes' => $bytesPerDocument,
        ]);
    }

    $export = DossierExport::factory()->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $dossier->id,
        'requested_by' => $owner->id,
        'status' => DossierExportStatus::Pending,
    ]);

    $pipeline = resolve(CreatePipelineAction::class)->handle($export, PipelineType::DossierExport, $owner);

    return ['export' => $export, 'dossier' => $dossier, 'pipelineId' => $pipeline->id];
}

test('a multi-file export does not grow memory with total archive size', function (): void {
    // Eight files of 1 MB. Under addFromString all eight are retained until
    // close(), so peak memory tracks the 8 MB total; staged and added lazily it
    // tracks one stream buffer.
    ['export' => $export, 'pipelineId' => $pipelineId] = dossierExportFixture(documentCount: 12, bytesPerDocument: 4_194_304);

    gc_collect_cycles();
    $before = memory_get_peak_usage(true);

    resolve(GenerateDossierExportAction::class)->handle($pipelineId);

    $growth = memory_get_peak_usage(true) - $before;

    $export->refresh();

    expect($export->status)->toBe(DossierExportStatus::Completed)
        ->and($export->path)->not->toBeNull()
        // Generous bound: the point is that growth does not track the 8 MB of
        // payload, not that it is zero.
        ->and($growth)->toBeLessThan(16 * 1_048_576);
});

test('an export archive contains every staged file intact', function (): void {
    ['export' => $export, 'pipelineId' => $pipelineId] = dossierExportFixture(documentCount: 3, bytesPerDocument: 2_048);

    resolve(GenerateDossierExportAction::class)->handle($pipelineId);

    $export->refresh();

    $archive = new ZipArchive();

    expect($archive->open(Storage::disk('local')->path((string) $export->path)))->toBeTrue();

    $names = [];

    for ($index = 0; $index < $archive->numFiles; $index++) {
        $names[] = (string) $archive->getNameIndex($index);
    }

    // Lazily-added entries must still be byte-identical to their sources.
    expect($names)->toContain('manifest.json')
        ->and($archive->getFromName('documents/001-scan-1.pdf'))->toBe(str_repeat('x', 2_048))
        ->and($archive->getFromName('documents/003-scan-3.pdf'))->toBe(str_repeat('x', 2_048));

    $archive->close();
});

test('staged copies are cleaned up after the archive is stored', function (): void {
    ['export' => $export, 'pipelineId' => $pipelineId] = dossierExportFixture(documentCount: 2, bytesPerDocument: 1_024);

    resolve(GenerateDossierExportAction::class)->handle($pipelineId);

    $leftovers = array_filter(
        Storage::disk('local')->allDirectories(),
        static fn (string $directory): bool => str_starts_with($directory, 'dossier-export-staging-'),
    );

    expect($leftovers)->toBeEmpty();
});

/**
 * buildArchive() throws before ZipArchive::open() creates the file when a
 * dossier has no client, so a bare unlink() in the finally warned on top of the
 * real exception. GenerateDataSubjectExportAction already guarded this.
 */
test('cleanup does not warn when the archive was never created', function (): void {
    ['dossier' => $dossier, 'pipelineId' => $pipelineId] = dossierExportFixture(
        documentCount: 1,
        bytesPerDocument: 512,
    );

    // A dossier whose client is gone makes buildArchive() bail before open().
    $dossier->client?->delete();

    $warnings = [];

    set_error_handler(function (int $severity, string $message) use (&$warnings): bool {
        $warnings[] = $message;

        return true;
    }, E_WARNING);

    try {
        expect(fn () => resolve(GenerateDossierExportAction::class)->handle($pipelineId))
            ->toThrow(RuntimeException::class);
    } finally {
        restore_error_handler();
    }

    expect(array_filter($warnings, static fn (string $message): bool => str_contains($message, 'unlink')))
        ->toBeEmpty();
});
