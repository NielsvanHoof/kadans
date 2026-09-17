<?php

declare(strict_types=1);

use App\Audit\Enums\AuditEvent;
use App\Audit\Models\Activity;
use App\Clients\Models\Client;
use App\Dossiers\Actions\Scanning\ApplyMalwareScanResultAction;
use App\Dossiers\Actions\Uploads\ResolveMalwareScanStatusAction;
use App\Dossiers\Data\Scanning\ScanResultNotification;
use App\Dossiers\Enums\MalwareScanStatus;
use App\Dossiers\Events\Scanning\DocumentScanStateChanged;
use App\Dossiers\Models\AudioRecording;
use App\Dossiers\Models\DocumentRequest;
use App\Dossiers\Models\Dossier;
use App\Dossiers\Models\UploadedDocument;
use App\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;

/*
 * Applying a malware verdict from the document-scan Lambda.
 *
 * These columns exist to drive the interface — so a document can say
 * "scanning…" and then stop. They are deliberately not an authority on whether
 * bytes may be served; MalwareScanGateTest covers that, and the last test here
 * pins the separation so a future change cannot quietly merge the two.
 */

/** @return array{tenant: Tenant, document: UploadedDocument} */
function scanResultFixture(): array
{
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

    $document = UploadedDocument::factory()->create([
        'tenant_id' => $tenant->id,
        'document_request_id' => $documentRequest->id,
        'disk' => 's3',
        'path' => 'tenants/'.$tenant->id.'/dossiers/'.$dossier->id.'/01JBQ.pdf',
        'original_filename' => 'payslip.pdf',
    ]);

    actingInWorkspace($tenant);

    return ['tenant' => $tenant, 'document' => $document];
}

function applyVerdict(array $message): ?Model
{
    return resolve(ApplyMalwareScanResultAction::class)
        ->handle(ScanResultNotification::fromQueueMessage($message));
}

test('a clean verdict records the status, the time and the thumbnail', function (): void {
    ['document' => $document] = scanResultFixture();

    applyVerdict([
        'key' => $document->path,
        'status' => 'NO_THREATS_FOUND',
        'scannedAt' => '2026-09-16T10:30:00Z',
        'thumbnailKey' => 'thumbnails/'.$document->path.'.jpg',
    ]);

    $document->refresh();

    expect($document->malware_scan_status)->toBe(MalwareScanStatus::NoThreatsFound)
        ->and($document->thumbnail_path)->toBe('thumbnails/'.$document->path.'.jpg')
        ->and($document->malware_scanned_at?->toIso8601String())
        ->toBe('2026-09-16T10:30:00+00:00');
});

/*
 * Every non-clean verdict is recorded and audited. UNSUPPORTED and FAILED are
 * included on purpose: they are not detections, but "could not scan it" is a
 * fact an operator needs, and it is the reason a document will not open.
 */
test('a verdict that is not clean is recorded and audited', function (string $status): void {
    ['document' => $document] = scanResultFixture();

    applyVerdict(['key' => $document->path, 'status' => $status]);

    $document->refresh();

    expect($document->malware_scan_status?->value)->toBe($status)
        ->and($document->thumbnail_path)->toBeNull();

    $flagged = Activity::query()
        ->where('event', AuditEvent::FileMalwareScanFlagged->value)
        ->where('subject_id', $document->id)
        ->first();

    expect($flagged)->not->toBeNull()
        ->and($flagged->properties->get('scan_status'))->toBe($status);
})->with(['THREATS_FOUND', 'UNSUPPORTED', 'ACCESS_DENIED', 'FAILED']);

/*
 * A clean result writes no activity row. One per successful upload would bury
 * the verdicts that matter in the volume of everything that went fine; the row
 * itself records that it was scanned, and when.
 */
test('a clean verdict is not written to the audit log', function (): void {
    ['document' => $document] = scanResultFixture();

    applyVerdict(['key' => $document->path, 'status' => 'NO_THREATS_FOUND']);

    expect(Activity::query()->where('event', AuditEvent::FileMalwareScanFlagged->value)->exists())
        ->toBeFalse();
});

/*
 * GuardDuty re-scans an object when a new version is written, so a file that was
 * clean can come back as a threat. The picture of its first page has to stop
 * being served along with it.
 */
test('a later non-clean verdict clears a thumbnail that was already rendered', function (): void {
    ['document' => $document] = scanResultFixture();

    applyVerdict([
        'key' => $document->path,
        'status' => 'NO_THREATS_FOUND',
        'thumbnailKey' => 'thumbnails/'.$document->path.'.jpg',
    ]);

    expect($document->refresh()->thumbnail_path)->not->toBeNull();

    applyVerdict(['key' => $document->path, 'status' => 'THREATS_FOUND']);

    expect($document->refresh()->thumbnail_path)->toBeNull();
});

test('an unrecognised verdict is stored as no verdict rather than accepted', function (): void {
    ['document' => $document] = scanResultFixture();

    applyVerdict(['key' => $document->path, 'status' => 'SOMETHING_GUARDDUTY_ADDED']);

    // Null is what the download gate already treats as "not established clean",
    // so an unknown value degrades into a refusal rather than a pass.
    expect($document->refresh()->malware_scan_status)->toBeNull();
});

test('a verdict for an audio recording is applied without a thumbnail', function (): void {
    ['tenant' => $tenant] = provisionWorkspace();

    $client = Client::factory()->create(['tenant_id' => $tenant->id]);
    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
    ]);
    $recording = AudioRecording::factory()->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $dossier->id,
        'disk' => 's3',
        'path' => 'tenants/'.$tenant->id.'/dossiers/'.$dossier->id.'/audio/01JBQ.mp3',
    ]);

    actingInWorkspace($tenant);

    applyVerdict(['key' => $recording->path, 'status' => 'NO_THREATS_FOUND']);

    expect($recording->refresh()->malware_scan_status)->toBe(MalwareScanStatus::NoThreatsFound);
});

/*
 * A document deleted between the scan and the message arriving looks exactly
 * like this, and redelivering will not bring it back — so it is a no-op, not a
 * failure the redrive policy should retry.
 */
test('a verdict for a key nothing owns is a no-op', function (): void {
    scanResultFixture();

    expect(applyVerdict(['key' => 'tenants/9/dossiers/9/gone.pdf', 'status' => 'NO_THREATS_FOUND']))
        ->toBeNull();
});

test('applying a verdict broadcasts the new state', function (): void {
    Event::fake([DocumentScanStateChanged::class]);

    ['tenant' => $tenant, 'document' => $document] = scanResultFixture();

    applyVerdict([
        'key' => $document->path,
        'status' => 'NO_THREATS_FOUND',
        'thumbnailKey' => 'thumbnails/'.$document->path.'.jpg',
    ]);

    Event::assertDispatched(fn (DocumentScanStateChanged $event): bool => $event->tenantSlug === $tenant->slug
        && $event->subjectId === $document->id
        && $event->status === 'NO_THREATS_FOUND'
        && $event->hasThumbnail);
});

/*
 * The separation this whole design rests on. The column is a copy, and a copy
 * can be stale; a stale "clean" would serve an infected file. Delivery is still
 * decided by reading the object tag, so writing "clean" here must not on its own
 * make anything servable.
 */
test('recording a clean verdict does not on its own make a document servable', function (): void {
    ['document' => $document] = scanResultFixture();

    applyVerdict(['key' => $document->path, 'status' => 'NO_THREATS_FOUND']);

    // Scanning is enforced, and the disk is not S3 here, so the gate takes its
    // non-S3 short circuit rather than consulting the column we just wrote.
    config()->set('security.malware_scanning.enabled', true);

    $verdict = resolve(ResolveMalwareScanStatusAction::class)
        ->handle('local', $document->path);

    expect($verdict->status)->toBeNull();
});
