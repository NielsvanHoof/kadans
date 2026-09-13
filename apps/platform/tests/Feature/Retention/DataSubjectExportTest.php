<?php

declare(strict_types=1);

use App\Accounts\Models\User;
use App\Audit\Enums\AuditEvent;
use App\Audit\Models\Activity;
use App\ClientPortal\Actions\Access\CreateClientAccessGrantAction;
use App\Clients\Models\Client;
use App\Dossiers\Enums\DocumentRequestStatus;
use App\Dossiers\Models\AudioRecording;
use App\Dossiers\Models\DocumentRequest;
use App\Dossiers\Models\Dossier;
use App\Dossiers\Models\UploadedDocument;
use App\Platform\Actions\DataSubject\GenerateDataSubjectExportAction;
use App\Platform\Actions\DataSubject\LogDataSubjectRequestAction;
use App\Platform\Actions\DataSubject\RefuseDataSubjectRequestAction;
use App\Platform\Enums\DataSubjectRequestStatus;
use App\Platform\Enums\DataSubjectRequestType;
use App\Platform\Jobs\DataSubject\GenerateDataSubjectExportJob;
use App\Platform\Models\DataSubjectRequest;
use App\Platform\Transitions\DataSubject\DataSubjectRequestTransitions;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * @return array{owner: User, tenant: Tenant, client: Client, dossier: Dossier}
 */
function dataSubjectFixture(): array
{
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();

    $client = Client::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Marieke de Vries',
        'email' => 'marieke@example.com',
    ]);

    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
        'title' => 'Annual accounts 2025',
    ]);

    return ['owner' => $owner, 'tenant' => $tenant, 'client' => $client, 'dossier' => $dossier];
}

test('logging a request records a due date and an audit entry', function (): void {
    ['owner' => $owner, 'client' => $client] = dataSubjectFixture();

    $request = resolve(LogDataSubjectRequestAction::class)
        ->handle($client, $owner, DataSubjectRequestType::Export);

    expect($request->status)->toBe(DataSubjectRequestStatus::Pending)
        ->and($request->due_at->toDateString())
        ->toBe(now()->addDays((int) config('retention.data_subject_response_days'))->toDateString())
        ->and($request->requested_by)->toBe($owner->id);

    $activity = Activity::query()
        ->where('event', AuditEvent::DataSubjectRequestLogged->value)
        ->latest('id')
        ->first();

    expect($activity)->not->toBeNull()
        ->and($activity->subject_id)->toBe($request->id)
        ->and($activity->causer_id)->toBe($owner->id);
});

/**
 * Two open requests of the same kind would mean two due dates for one
 * obligation and two artefacts to keep track of.
 */
test('a second open request of the same kind is refused', function (): void {
    ['owner' => $owner, 'client' => $client] = dataSubjectFixture();

    $action = resolve(LogDataSubjectRequestAction::class);
    $action->handle($client, $owner, DataSubjectRequestType::Export);

    expect(fn () => $action->handle($client, $owner, DataSubjectRequestType::Export))
        ->toThrow(ValidationException::class);

    // A different right over the same client is a separate obligation.
    $erasure = $action->handle($client, $owner, DataSubjectRequestType::Erasure);

    expect($erasure->type)->toBe(DataSubjectRequestType::Erasure);
});

test('the export archive carries the client, dossiers, answers, and stored files', function (): void {
    Storage::fake('local');
    config(['exports.disk' => 'local']);

    ['owner' => $owner, 'tenant' => $tenant, 'client' => $client, 'dossier' => $dossier] = dataSubjectFixture();

    $documentRequest = DocumentRequest::factory()->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $dossier->id,
        'title' => 'Bank statement',
        'status' => DocumentRequestStatus::Submitted,
        'answer_text' => 'Attached the January statement.',
    ]);

    Storage::disk('local')->put('documents/statement.pdf', 'pdf-bytes');
    UploadedDocument::factory()->create([
        'tenant_id' => $tenant->id,
        'document_request_id' => $documentRequest->id,
        'disk' => 'local',
        'path' => 'documents/statement.pdf',
        'original_filename' => 'January statement.pdf',
    ]);

    Storage::disk('local')->put('audio/call.mp3', 'audio-bytes');
    AudioRecording::factory()->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $dossier->id,
        'disk' => 'local',
        'path' => 'audio/call.mp3',
        'original_filename' => 'Client call.mp3',
        'transcript' => 'We discussed the annual accounts.',
    ]);

    resolve(CreateClientAccessGrantAction::class)->handle($dossier, $owner, 7);

    $request = resolve(LogDataSubjectRequestAction::class)
        ->handle($client, $owner, DataSubjectRequestType::Export);

    resolve(GenerateDataSubjectExportAction::class)->handle($request, dataSubjectExportStaleCutoff());

    $request->refresh();

    expect($request->status)->toBe(DataSubjectRequestStatus::Completed)
        ->and($request->isDownloadable())->toBeTrue();

    $archivePath = Storage::disk((string) config('exports.disk'))->path((string) $request->path);
    $zip = new ZipArchive();

    expect($zip->open($archivePath))->toBeTrue();

    $manifest = json_decode((string) $zip->getFromName('data.json'), associative: true);

    expect($manifest['client']['name'])->toBe('Marieke de Vries')
        ->and($manifest['client']['email'])->toBe('marieke@example.com')
        ->and($manifest['dossiers'][0]['title'])->toBe('Annual accounts 2025')
        ->and($manifest['dossiers'][0]['document_requests'][0]['answer_text'])
        ->toBe('Attached the January statement.')
        ->and($manifest['dossiers'][0]['audio_recordings'][0]['transcript'])
        ->toBe('We discussed the annual accounts.')
        ->and($manifest['portal_access'])->toHaveCount(1);

    // The files themselves, not just their metadata.
    $documentPath = $manifest['dossiers'][0]['document_requests'][0]['files'][0]['archive_path'];
    $audioPath = $manifest['dossiers'][0]['audio_recordings'][0]['archive_path'];

    expect($zip->getFromName($documentPath))->toBe('pdf-bytes')
        ->and($zip->getFromName($audioPath))->toBe('audio-bytes');

    $zip->close();
});

/**
 * The grant token is a live credential, hashed at rest. Handing it to the
 * subject would be handing out access, not disclosing data.
 */
test('the export never contains a portal token', function (): void {
    Storage::fake('local');
    config(['exports.disk' => 'local']);

    ['owner' => $owner, 'client' => $client, 'dossier' => $dossier] = dataSubjectFixture();

    $grant = resolve(CreateClientAccessGrantAction::class)->handle($dossier, $owner, 7);

    $request = resolve(LogDataSubjectRequestAction::class)
        ->handle($client, $owner, DataSubjectRequestType::Export);

    resolve(GenerateDataSubjectExportAction::class)->handle($request, dataSubjectExportStaleCutoff());

    $zip = new ZipArchive();
    $zip->open(Storage::disk((string) config('exports.disk'))->path((string) $request->refresh()->path));
    $manifest = (string) $zip->getFromName('data.json');
    $zip->close();

    expect($manifest)->not->toContain($grant['plain_text_token'])
        ->and($manifest)->not->toContain($grant['grant']->token);
});

test('an export artefact expires so a copy of one person does not linger', function (): void {
    Storage::fake('local');
    config(['exports.disk' => 'local']);

    ['owner' => $owner, 'client' => $client] = dataSubjectFixture();

    $request = resolve(LogDataSubjectRequestAction::class)
        ->handle($client, $owner, DataSubjectRequestType::Export);

    resolve(GenerateDataSubjectExportAction::class)->handle($request, dataSubjectExportStaleCutoff());

    $request->refresh();

    expect($request->expires_at?->toDateTimeString())->toBe(
        now()->addHours((int) config('retention.data_subject_export_hours'))->toDateTimeString(),
    );

    $this->travel((int) config('retention.data_subject_export_hours') + 1)->hours();

    expect($request->isDownloadable())->toBeFalse();
});

test('generating twice does nothing the second time', function (): void {
    Storage::fake('local');
    config(['exports.disk' => 'local']);

    ['owner' => $owner, 'client' => $client] = dataSubjectFixture();

    $request = resolve(LogDataSubjectRequestAction::class)
        ->handle($client, $owner, DataSubjectRequestType::Export);

    $action = resolve(GenerateDataSubjectExportAction::class);
    $action->handle($request, dataSubjectExportStaleCutoff());
    $completedAt = $request->refresh()->completed_at;

    $action->handle($request->refresh(), dataSubjectExportStaleCutoff());

    expect($request->refresh()->completed_at?->toDateTimeString())
        ->toBe($completedAt?->toDateTimeString())
        ->and(Activity::query()
            ->where('event', AuditEvent::DataSubjectExportCompleted->value)
            ->count())->toBe(1);
});

test('refusing a request records who refused it and why', function (): void {
    ['owner' => $owner, 'client' => $client] = dataSubjectFixture();

    $request = resolve(LogDataSubjectRequestAction::class)
        ->handle($client, $owner, DataSubjectRequestType::Erasure);

    resolve(RefuseDataSubjectRequestAction::class)->handle(
        $request,
        $owner,
        'The dossiers fall inside the statutory seven-year retention period.',
    );

    $request->refresh();

    expect($request->status)->toBe(DataSubjectRequestStatus::Refused)
        ->and($request->refused_by)->toBe($owner->id)
        ->and($request->refusal_reason)->toContain('seven-year')
        ->and(Activity::query()
            ->where('event', AuditEvent::DataSubjectRequestRefused->value)
            ->where('subject_id', $request->id)
            ->exists())
        ->toBeTrue();
});

test('a refusal must state a reason and cannot reopen a closed request', function (): void {
    ['owner' => $owner, 'client' => $client] = dataSubjectFixture();

    $request = resolve(LogDataSubjectRequestAction::class)
        ->handle($client, $owner, DataSubjectRequestType::Erasure);

    $action = resolve(RefuseDataSubjectRequestAction::class);

    expect(fn () => $action->handle($request, $owner, '   '))
        ->toThrow(ValidationException::class);

    $action->handle($request, $owner, 'Retention duty applies.');

    expect(fn () => $action->handle($request->refresh(), $owner, 'Changed my mind.'))
        ->toThrow(ValidationException::class);
});

test('an overdue open request is flagged', function (): void {
    ['tenant' => $tenant, 'client' => $client] = dataSubjectFixture();

    $overdue = DataSubjectRequest::factory()->overdue()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
    ]);

    $onTime = DataSubjectRequest::factory()->erasure()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
    ]);

    expect($overdue->isOverdue())->toBeTrue()
        ->and($onTime->isOverdue())->toBeFalse();
});

/**
 * Spatie makes queues tenant-aware by default, so the tenant current at dispatch
 * is restored before the job runs. The job therefore holds no tenant logic — it
 * only has to survive a request that vanished in between.
 */
test('the export job fulfils the request and tolerates one that vanished', function (): void {
    Storage::fake('local');
    config(['exports.disk' => 'local']);

    ['owner' => $owner, 'client' => $client] = dataSubjectFixture();

    $request = resolve(LogDataSubjectRequestAction::class)
        ->handle($client, $owner, DataSubjectRequestType::Export);

    dispatch_sync(new GenerateDataSubjectExportJob($request->id));

    expect($request->refresh()->status)->toBe(DataSubjectRequestStatus::Completed);

    // A request deleted between dispatch and execution must not fail a worker.
    dispatch_sync(new GenerateDataSubjectExportJob(999_999));

    expect(true)->toBeTrue();
});

/**
 * This export is the worse of the two for peak memory: it can contain every
 * document AND every audio recording ever held about one person, and its job
 * carries a 600s timeout with no memory hint. ZipArchive::addFromString() keeps
 * every entry's bytes until close(), so RSS tracked the whole archive.
 */
test('a data-subject export does not grow memory with total archive size', function (): void {
    Storage::fake('local');
    config(['exports.disk' => 'local']);

    ['owner' => $owner, 'tenant' => $tenant, 'client' => $client, 'dossier' => $dossier] = dataSubjectFixture();

    $documentRequest = DocumentRequest::factory()->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $dossier->id,
        'status' => DocumentRequestStatus::Submitted,
    ]);

    // 12 x 4 MB across both file kinds the export gathers.
    foreach (range(1, 6) as $index) {
        $documentPath = sprintf('tenants/%d/dossiers/%d/scan-%d.pdf', $tenant->id, $dossier->id, $index);
        Storage::disk('local')->put($documentPath, str_repeat('x', 4_194_304));

        UploadedDocument::factory()->create([
            'tenant_id' => $tenant->id,
            'document_request_id' => $documentRequest->id,
            'disk' => 'local',
            'path' => $documentPath,
            'original_filename' => sprintf('scan-%d.pdf', $index),
            'size_bytes' => 4_194_304,
        ]);

        $audioPath = sprintf('tenants/%d/dossiers/%d/audio/call-%d.m4a', $tenant->id, $dossier->id, $index);
        Storage::disk('local')->put($audioPath, str_repeat('y', 4_194_304));

        AudioRecording::factory()->create([
            'tenant_id' => $tenant->id,
            'dossier_id' => $dossier->id,
            'disk' => 'local',
            'path' => $audioPath,
            'original_filename' => sprintf('call-%d.m4a', $index),
            'size_bytes' => 4_194_304,
        ]);
    }

    $request = resolve(LogDataSubjectRequestAction::class)
        ->handle($client, $owner, DataSubjectRequestType::Export);

    gc_collect_cycles();
    $before = memory_get_peak_usage(true);

    resolve(GenerateDataSubjectExportAction::class)->handle($request, dataSubjectExportStaleCutoff());

    $growth = memory_get_peak_usage(true) - $before;

    $request->refresh();

    expect($request->status)->toBe(DataSubjectRequestStatus::Completed)
        ->and($request->path)->not->toBeNull()
        // Generous bound: growth must not track the 48 MB of payload.
        ->and($growth)->toBeLessThan(16 * 1_048_576)
        // And nothing is left staged behind.
        ->and(array_filter(
            Storage::disk('local')->allDirectories(),
            static fn (string $directory): bool => str_starts_with($directory, 'data-subject-export-staging-'),
        ))->toBeEmpty();
});

describe('fulfilment claim', function (): void {
    /*
     * ShouldBeUnique stops a second *dispatch*; it does nothing once Redis
     * re-reserves a job at retry_after, which is 660s against this job's 600s
     * timeout. So the claim, not the unique lock, is what keeps two workers off
     * the same ten-minute archive build.
     */

    test('a second worker arriving mid-build does not build a second archive', function (): void {
        Storage::fake('local');
        config(['exports.disk' => 'local']);

        ['owner' => $owner, 'client' => $client] = dataSubjectFixture();

        $request = resolve(LogDataSubjectRequestAction::class)
            ->handle($client, $owner, DataSubjectRequestType::Export);

        // Stand in for the worker that is already zipping: it holds the claim
        // and has not reached the Completed write, so status is still Pending.
        $claimed = resolve(DataSubjectRequestTransitions::class)
            ->claimForFulfilment($request, dataSubjectExportStaleCutoff());

        expect($claimed)->toBeTrue()
            ->and($request->refresh()->status)->toBe(DataSubjectRequestStatus::Pending);

        resolve(GenerateDataSubjectExportAction::class)
            ->handle($request->refresh(), dataSubjectExportStaleCutoff());

        expect($request->refresh()->completed_at)->toBeNull()
            ->and($request->path)->toBeNull()
            ->and(Storage::disk('local')->allFiles())
            ->toBeEmpty()
            ->and(Activity::query()
                ->where('event', AuditEvent::DataSubjectExportCompleted->value)
                ->count())->toBe(0);
    });

    test('a claim older than the jobs lifecycle is reclaimed without an operator', function (): void {
        Storage::fake('local');
        config(['exports.disk' => 'local']);

        ['owner' => $owner, 'client' => $client] = dataSubjectFixture();

        $request = resolve(LogDataSubjectRequestAction::class)
            ->handle($client, $owner, DataSubjectRequestType::Export);

        // A worker that was SIGKILLed: claimed, never released, long gone.
        $request->forceFill([
            'fulfilment_started_at' => now()->subSeconds(
                GenerateDataSubjectExportJob::lifecycleSeconds() + 60,
            ),
        ])->save();

        resolve(GenerateDataSubjectExportAction::class)
            ->handle($request->refresh(), dataSubjectExportStaleCutoff());

        expect($request->refresh()->status)->toBe(DataSubjectRequestStatus::Completed)
            ->and($request->path)->not->toBeNull();
    });

    test('a failure hands the claim back so the retry does not wait out the window', function (): void {
        Storage::fake('local');
        config(['exports.disk' => 'local']);

        ['owner' => $owner, 'client' => $client] = dataSubjectFixture();

        $request = resolve(LogDataSubjectRequestAction::class)
            ->handle($client, $owner, DataSubjectRequestType::Export);

        // Nothing can be written, so the build throws after taking the claim.
        config(['exports.disk' => 'nonexistent-disk']);

        expect(fn (): mixed => resolve(GenerateDataSubjectExportAction::class)
            ->handle($request->refresh(), dataSubjectExportStaleCutoff()))
            ->toThrow(InvalidArgumentException::class)
            ->and($request->refresh()->fulfilment_started_at)
            ->toBeNull()
            ->and($request->status)
            ->toBe(DataSubjectRequestStatus::Pending);

        // And the immediate retry gets through rather than silently skipping.
        config(['exports.disk' => 'local']);

        resolve(GenerateDataSubjectExportAction::class)
            ->handle($request->refresh(), dataSubjectExportStaleCutoff());

        expect($request->refresh()->status)->toBe(DataSubjectRequestStatus::Completed);
    });
});
