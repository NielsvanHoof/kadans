<?php

declare(strict_types=1);

use App\Audit\Actions\LogAuditActivityAction;
use App\Audit\Enums\AuditEvent;
use App\Audit\Models\Activity;
use App\Clients\Models\Client;
use App\DocumentProcessing\Actions\Pipelines\CreatePipelineAction;
use App\DocumentProcessing\Enums\PipelineType;
use App\DocumentProcessing\Models\Pipeline;
use App\Dossiers\Actions\GenerateDossierExportAction;
use App\Dossiers\Enums\DossierExportStatus;
use App\Dossiers\Jobs\GenerateDossierExportJob;
use App\Dossiers\Models\DocumentRequest;
use App\Dossiers\Models\Dossier;
use App\Dossiers\Models\DossierExport;
use App\Dossiers\Models\UploadedDocument;
use App\Tenancy\Enums\Role;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

beforeEach(function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    Storage::fake('local');
    config(['exports.disk' => 'local']);
});

test('a permitted staff member can queue a dossier export and it is audited', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();
    $dossier = dossierForTenant($tenant->id);
    Queue::fake();

    $this->actingAs($owner)
        ->withSession(['active_tenant_id' => $tenant->id])
        ->post(workspaceRoute('workspaces.dossiers.exports.store', $tenant, [
            'dossier' => $dossier,
        ]))
        ->assertRedirect(workspaceRoute('workspaces.dossiers.show', $tenant, [
            'dossier' => $dossier,
        ]));

    $export = DossierExport::query()->sole();

    expect($export->status)->toBe(DossierExportStatus::Pending)
        ->and($export->requested_by)->toBe($owner->id)
        ->and(Activity::query()
            ->where('event', AuditEvent::DossierExportRequested->value)
            ->where('subject_id', $export->id)
            ->exists())->toBeTrue();

    $pipeline = $export->pipeline;

    expect($pipeline)->toBeInstanceOf(Pipeline::class);

    Queue::assertPushed(GenerateDossierExportJob::class, fn (GenerateDossierExportJob $job): bool => $job->pipelineId === $pipeline->id);
});

test('the export job creates a ZIP manifest and copies uploaded documents', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();
    $dossier = dossierForTenant($tenant->id);
    $request = DocumentRequest::factory()->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $dossier->id,
        'title' => 'Payslip',
    ]);
    $document = UploadedDocument::factory()->create([
        'tenant_id' => $tenant->id,
        'document_request_id' => $request->id,
        'path' => 'uploads/payslip.pdf',
        'original_filename' => 'Payslip January.pdf',
        'size_bytes' => 17,
    ]);
    Storage::disk('local')->put($document->path, 'uploaded-document');

    $export = DossierExport::factory()->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $dossier->id,
        'requested_by' => $owner->id,
    ]);
    $pipeline = resolve(CreatePipelineAction::class)->handle($export, PipelineType::DossierExport, $owner);

    resolve(GenerateDossierExportAction::class)->handle($pipeline->id);
    $export->refresh();

    expect($export->status)->toBe(DossierExportStatus::Completed)
        ->and($export->expires_at?->isFuture())->toBeTrue()
        ->and(Storage::disk('local')->exists($export->path))->toBeTrue();

    $archivePath = tempnam(sys_get_temp_dir(), 'dossier-export-test-');
    // tempnam() returns string|false when the temp file cannot be created.
    expect($archivePath)->toBeString();
    file_put_contents($archivePath, Storage::disk('local')->get($export->path));

    $archive = new ZipArchive();
    expect($archive->open($archivePath))->toBeTrue()
        ->and($archive->getFromName('documents/001-payslip-january.pdf'))->toBe('uploaded-document');

    $manifest = json_decode((string) $archive->getFromName('manifest.json'), true, flags: JSON_THROW_ON_ERROR);
    $archive->close();
    unlink($archivePath);

    expect($manifest['dossier']['id'])->toBe($dossier->id)
        ->and($manifest['documents'][0]['archive_path'])->toBe('documents/001-payslip-january.pdf')
        ->and(Activity::query()
            ->where('event', AuditEvent::DossierExportCompleted->value)
            ->where('subject_id', $export->id)
            ->exists())->toBeTrue();
});

test('reviewers cannot request or download dossier exports', function (): void {
    ['tenant' => $tenant] = provisionWorkspace();
    $reviewer = workspaceMember($tenant, Role::Reviewer);
    $dossier = dossierForTenant($tenant->id);
    $export = DossierExport::factory()->completed('dossier-exports/reviewer.zip')->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $dossier->id,
    ]);
    Storage::disk('local')->put($export->path, 'archive');

    $this->actingAs($reviewer)
        ->withSession([
            'active_tenant_id' => $tenant->id,
            'auth.password_confirmed_at' => now()->getTimestamp(),
        ])
        ->post(workspaceRoute('workspaces.dossiers.exports.store', $tenant, ['dossier' => $dossier]))
        ->assertForbidden();

    $url = URL::temporarySignedRoute(
        'workspaces.dossiers.exports.download',
        now()->addMinutes(5),
        ['tenant' => $tenant, 'dossier' => $dossier, 'export' => $export],
    );

    $this->get($url)->assertForbidden();
});

test('a signed export download is tenant-scoped, password-protected, and audited', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();
    $dossier = dossierForTenant($tenant->id, 'Confidential dossier');
    $export = DossierExport::factory()->completed('dossier-exports/confidential.zip')->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $dossier->id,
    ]);
    Storage::disk('local')->put($export->path, 'archive');

    $url = URL::temporarySignedRoute(
        'workspaces.dossiers.exports.download',
        $export->expires_at,
        ['tenant' => $tenant, 'dossier' => $dossier, 'export' => $export],
    );

    $this->actingAs($owner)
        ->withSession([
            'active_tenant_id' => $tenant->id,
            'auth.password_confirmed_at' => now()->getTimestamp(),
        ])
        ->get($url)
        ->assertOk()
        ->assertHeader('content-disposition', 'attachment; filename=confidential-dossier-export.zip')
        ->assertHeader('content-type', 'application/zip');

    expect(Activity::query()
        ->where('event', AuditEvent::DossierExportDownloaded->value)
        ->where('subject_id', $export->id)
        ->exists())->toBeTrue();

    $otherWorkspace = provisionWorkspace('Other Accountants');
    $this->actingAs($otherWorkspace['owner'])
        ->withSession(['active_tenant_id' => $otherWorkspace['tenant']->id])
        ->get($url)
        ->assertNotFound();
});

test('expired export archives are deleted by retention pruning', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();
    $dossier = dossierForTenant($tenant->id);
    $export = DossierExport::factory()->completed('dossier-exports/expired.zip')->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $dossier->id,
        'expires_at' => now()->subMinute(),
    ]);
    Storage::disk('local')->put($export->path, 'archive');
    resolve(LogAuditActivityAction::class)->handle(
        AuditEvent::DossierExportCompleted,
        $export,
        [],
        $owner,
        false,
    );

    $this->artisan('retention:prune-dossier-exports')->assertSuccessful();

    expect(DossierExport::query()->find($export->id))->toBeNull()
        ->and(Storage::disk('local')->exists('dossier-exports/expired.zip'))->toBeFalse();
});

test('legal retention removes dossier exports and their audit records', function (): void {
    config(['retention.archived_days' => 30]);
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();
    $dossier = dossierForTenant($tenant->id);
    $export = DossierExport::factory()->completed('dossier-exports/legal.zip')->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $dossier->id,
    ]);
    $pipeline = resolve(CreatePipelineAction::class)->handle($export, PipelineType::DossierExport, $owner);
    Storage::disk('local')->put($export->path, 'archive');
    resolve(LogAuditActivityAction::class)->handle(
        AuditEvent::DossierExportCompleted,
        $export,
        [],
        $owner,
        false,
    );

    $dossier->delete();
    Dossier::withTrashed()->whereKey($dossier->id)->update(['deleted_at' => now()->subDays(31)]);

    $this->artisan('retention:purge-legal')->assertSuccessful();

    expect(DossierExport::query()->find($export->id))->toBeNull()
        ->and(Pipeline::query()->find($pipeline->id))->toBeNull()
        ->and(Storage::disk('local')->exists('dossier-exports/legal.zip'))->toBeFalse()
        ->and(Activity::query()
            ->where('subject_type', (new DossierExport)->getMorphClass())
            ->where('subject_id', $export->id)
            ->exists())->toBeFalse();
});

test('export status island returns json for a dossier export', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();
    $dossier = dossierForTenant($tenant->id);
    $export = DossierExport::factory()->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $dossier->id,
        'requested_by' => $owner->id,
        'status' => DossierExportStatus::Processing,
    ]);
    resolve(CreatePipelineAction::class)->handle($export, PipelineType::DossierExport, $owner);

    $this->actingAs($owner)
        ->withSession(['active_tenant_id' => $tenant->id])
        ->getJson(workspaceRoute('workspaces.dossiers.exports.show', $tenant, [
            'dossier' => $dossier,
            'export' => $export,
        ]))
        ->assertOk()
        ->assertJsonPath('export.id', $export->id)
        ->assertJsonPath('export.status', DossierExportStatus::Processing->value)
        ->assertJsonStructure(['export' => ['id', 'status', 'created_at', 'pipeline']]);
});

function dossierForTenant(int $tenantId, string $title = 'Test dossier'): Dossier
{
    $client = Client::factory()->create(['tenant_id' => $tenantId]);

    return Dossier::factory()->create([
        'tenant_id' => $tenantId,
        'client_id' => $client->id,
        'title' => $title,
    ]);
}
