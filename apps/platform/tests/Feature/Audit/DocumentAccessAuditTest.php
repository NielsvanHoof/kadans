<?php

declare(strict_types=1);

use App\Audit\Enums\AuditEvent;
use App\Audit\Models\Activity;
use App\Clients\Models\Client;
use App\DocumentProcessing\Enums\DocumentProcessingStatus;
use App\Dossiers\Models\DocumentRequest;
use App\Dossiers\Models\Dossier;
use App\Dossiers\Models\UploadedDocument;
use App\Tenancy\Enums\Role;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\Storage;

/**
 * `preview` and `show` hand over the same document content as `download`, so a
 * reader must not be able to pull every payslip in the workspace while leaving
 * the trail showing nothing.
 */
test('opening the extraction page writes a document viewed audit entry', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();
    $uploaded = createAuditedUploadedDocument();

    $this->actingAs($owner)
        ->withSession(['auth.password_confirmed_at' => now()->getTimestamp()])
        ->get(workspaceRoute('workspaces.uploaded-documents.show', $tenant, [
            'uploadedDocument' => $uploaded,
        ]))
        ->assertOk();

    $activity = Activity::query()
        ->where('event', AuditEvent::DocumentViewed->value)
        ->sole();

    expect($activity->subject_id)->toBe($uploaded->id)
        ->and($activity->subject_type)->toBe('uploaded_document')
        ->and($activity->tenant_id)->toBe($tenant->id)
        ->and($activity->causer_id)->toBe($owner->id)
        ->and($activity->getProperty('surface'))->toBe('extraction')
        ->and($activity->getProperty('original_filename'))->toBe('payslip.pdf');
});

/**
 * The extraction island re-fetches this endpoint on every PipelineUpdated
 * broadcast while OCR runs, so auditing the JSON branch would write a row per
 * broadcast — the flooding .ai/rules/pipelines.md warns about.
 */
test('the extraction island json refresh writes no audit entry', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();
    $uploaded = createAuditedUploadedDocument();

    $this->actingAs($owner)
        ->withSession(['auth.password_confirmed_at' => now()->getTimestamp()])
        ->getJson(workspaceRoute('workspaces.uploaded-documents.show', $tenant, [
            'uploadedDocument' => $uploaded,
        ]))
        ->assertOk();

    expect(Activity::query()->where('event', AuditEvent::DocumentViewed->value)->exists())
        ->toBeFalse();
});

test('previewing a document writes a document viewed audit entry', function (): void {
    Storage::fake('local');

    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();
    $uploaded = createAuditedUploadedDocument();
    Storage::disk('local')->put($uploaded->path, 'pdf-bytes');

    $this->actingAs($owner)
        ->withSession(['auth.password_confirmed_at' => now()->getTimestamp()])
        ->get(workspaceRoute('workspaces.uploaded-documents.preview', $tenant, [
            'uploadedDocument' => $uploaded,
        ]))
        ->assertOk();

    $activity = Activity::query()
        ->where('event', AuditEvent::DocumentViewed->value)
        ->sole();

    expect($activity->subject_id)->toBe($uploaded->id)
        ->and($activity->getProperty('surface'))->toBe('preview');
});

/**
 * `live` is polled and carries no document content, so it stays out of the trail.
 */
test('the live status island writes no audit entry', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();
    $uploaded = createAuditedUploadedDocument();

    $this->actingAs($owner)
        ->getJson(workspaceRoute('workspaces.uploaded-documents.live', $tenant, [
            'uploadedDocument' => $uploaded,
        ]))
        ->assertOk();

    expect(Activity::query()->where('event', AuditEvent::DocumentViewed->value)->exists())
        ->toBeFalse();
});

test('a denied policy check writes exactly one access denied audit entry', function (): void {
    ['tenant' => $tenant] = provisionWorkspace();
    $uploaded = createAuditedUploadedDocument();
    $reader = workspaceMember($tenant, Role::ReadOnly);

    $this->actingAs($reader)
        ->withSession(['auth.password_confirmed_at' => now()->getTimestamp()])
        ->get(workspaceRoute('workspaces.uploaded-documents.download', $tenant, [
            'uploadedDocument' => $uploaded,
        ]))
        ->assertForbidden();

    $activity = Activity::query()
        ->where('event', AuditEvent::AccessDenied->value)
        ->sole();

    expect($activity->tenant_id)->toBe($tenant->id)
        ->and($activity->causer_id)->toBe($reader->id)
        ->and($activity->getProperty('method'))->toBe('GET')
        ->and($activity->getProperty('status'))->toBe(403)
        ->and($activity->getProperty('route'))
        ->toBe('workspaces.uploaded-documents.download');
});

/**
 * Every index and show payload computes `can_*` props with ->can(), several per
 * row. Those probes must stay out of the trail, or a read-only member opening a
 * dossier would bury the real denials.
 */
test('rendering a page whose capability probes fail writes no access denied entries', function (): void {
    ['tenant' => $tenant] = provisionWorkspace();
    createAuditedUploadedDocument();
    $reader = workspaceMember($tenant, Role::ReadOnly);

    $this->actingAs($reader)
        ->get(workspaceRoute('workspaces.dossiers.index', $tenant))
        ->assertOk();

    expect(Activity::query()->where('event', AuditEvent::AccessDenied->value)->exists())
        ->toBeFalse();
});

function createAuditedUploadedDocument(): UploadedDocument
{
    $tenantId = Tenant::current()?->id;

    $client = Client::factory()->create(['tenant_id' => $tenantId]);
    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenantId,
        'client_id' => $client->id,
    ]);
    $documentRequest = DocumentRequest::factory()->create([
        'tenant_id' => $tenantId,
        'dossier_id' => $dossier->id,
    ]);

    return UploadedDocument::factory()->processed()->create([
        'tenant_id' => $tenantId,
        'document_request_id' => $documentRequest->id,
        'disk' => 'local',
        'original_filename' => 'payslip.pdf',
        'processing_status' => DocumentProcessingStatus::Completed,
    ]);
}
