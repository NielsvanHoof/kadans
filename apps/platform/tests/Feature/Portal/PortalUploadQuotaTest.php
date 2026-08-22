<?php

declare(strict_types=1);

use App\ClientPortal\Actions\CreateClientAccessGrantAction;
use App\ClientPortal\Actions\SubmitPortalUploadAction;
use App\ClientPortal\Models\ClientAccessGrant;
use App\Clients\Models\Client;
use App\Dossiers\Enums\DocumentRequestStatus;
use App\Dossiers\Enums\DossierStatus;
use App\Dossiers\Models\DocumentRequest;
use App\Dossiers\Models\Dossier;
use App\Dossiers\Models\UploadedDocument;
use App\Dossiers\Support\DocumentUploadLimits;
use App\Pipelines\Models\Pipeline;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

covers(SubmitPortalUploadAction::class, DocumentUploadLimits::class);

/**
 * @return array{plainTextToken: string, tenant: mixed, dossier: Dossier, grant: ClientAccessGrant}
 */
function portalQuotaWorkspace(): array
{
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();

    $client = Client::factory()->create([
        'tenant_id' => $tenant->id,
        'email' => 'quota-client@example.com',
    ]);
    $dossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
        'title' => 'Quota dossier',
        'status' => DossierStatus::Draft,
    ]);

    $result = resolve(CreateClientAccessGrantAction::class)->handle($dossier, $owner, 7);

    return [
        'tenant' => $tenant,
        'dossier' => $dossier->fresh(),
        'plainTextToken' => $result['plain_text_token'],
        'grant' => $result['grant'],
    ];
}

test('portal upload is rejected when the dossier byte quota would be exceeded', function (): void {
    Storage::fake('local');
    config(['filesystems.default' => 'local']);

    $context = portalQuotaWorkspace();
    actingInWorkspace($context['tenant']);

    $filledRequest = DocumentRequest::factory()->createOne([
        'tenant_id' => $context['tenant']->id,
        'dossier_id' => $context['dossier']->id,
        'title' => 'Already uploaded payslip',
        'status' => DocumentRequestStatus::Submitted,
    ]);

    UploadedDocument::factory()->createOne([
        'tenant_id' => $context['tenant']->id,
        'document_request_id' => $filledRequest->id,
        'size_bytes' => DocumentUploadLimits::MAX_PORTAL_BYTES_PER_DOSSIER - 1_000,
    ]);

    $openRequest = DocumentRequest::factory()->createOne([
        'tenant_id' => $context['tenant']->id,
        'dossier_id' => $context['dossier']->id,
        'title' => 'Extra bank statement',
        'status' => DocumentRequestStatus::Pending,
    ]);

    $this->get(route('portal.exchange', $context['plainTextToken']));

    // 120 KB pushes the dossier over the remaining ~1 KB.
    $ticket = stagedUploadTicket($context['tenant']->id, 'statement.pdf', sizeBytes: 120 * 1024);

    $this->from(route('portal.show'))
        ->post(
            route('portal.document-requests.upload', [
                'documentRequest' => $openRequest->id,
            ]),
            ['documents' => [$ticket]],
        )
        ->assertRedirect(route('portal.show'))
        ->assertSessionHasErrors([
            'documents' => 'This dossier has reached its portal upload limit of '.DocumentUploadLimits::MAX_PORTAL_BYTES_PER_DOSSIER_LABEL.'. Remove a file or contact your advisor.',
        ]);

    actingInWorkspace($context['tenant']);

    expect(UploadedDocument::query()->where('document_request_id', $openRequest->id)->exists())->toBeFalse()
        ->and(UploadedDocument::query()->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Write-time enforcement
|--------------------------------------------------------------------------
|
| The tests above go over HTTP, where the cap is checked twice: once when the
| upload ticket is issued, so a client learns the dossier is full before
| spending bandwidth on a 20 MB file, and once under the dossier lock on write.
| Only the second is authoritative. The pre-check sums bytes before the write,
| and that sum cannot hold under concurrency: at throttle:20,1 with 10 files of
| 20 MB, every in-flight request reads the same pre-write total and every one
| of them passes. The tests below drive SubmitPortalUploadAction directly,
| which is what a concurrent POST looks like from the cap's point of view —
| the pre-check already happened, against a total a sibling request has since
| invalidated.
|
| A literal two-connection test is not possible here: the suite runs on
| SQLite :memory:, where lockForUpdate() compiles to nothing. So the lock is
| asserted the way tests/Feature/Tenancy/LastOwnerMutationLockTest.php does
| it — prove the lock query is emitted, and assert the SQL only under pgsql.
|
*/

test('the write-time cap rejects an upload whose pre-write sum went stale', function (): void {
    Storage::fake('local');
    config(['filesystems.default' => 'local']);

    $context = portalQuotaWorkspace();
    actingInWorkspace($context['tenant']);

    $filledRequest = DocumentRequest::factory()->createOne([
        'tenant_id' => $context['tenant']->id,
        'dossier_id' => $context['dossier']->id,
        'title' => 'Already uploaded payslip',
        'status' => DocumentRequestStatus::Submitted,
    ]);

    // Leaves ~1 KB of headroom, which a sibling POST has effectively consumed.
    UploadedDocument::factory()->createOne([
        'tenant_id' => $context['tenant']->id,
        'document_request_id' => $filledRequest->id,
        'size_bytes' => DocumentUploadLimits::MAX_PORTAL_BYTES_PER_DOSSIER - 1_000,
    ]);

    $openRequest = DocumentRequest::factory()->createOne([
        'tenant_id' => $context['tenant']->id,
        'dossier_id' => $context['dossier']->id,
        'title' => 'Extra bank statement',
        'status' => DocumentRequestStatus::Pending,
    ]);

    $submit = static fn (): UploadedDocument => resolve(SubmitPortalUploadAction::class)->handle(
        $openRequest,
        $context['grant'],
        stagedUpload($context['tenant']->id, 'statement.pdf', 'application/pdf', 120 * 1024),
    );

    // Assert the message, not just the class: a ValidationException from some
    // other guard would otherwise make this pass without the cap existing.
    expect($submit)->toThrow(
        ValidationException::class,
        'This dossier has reached its portal upload limit of '
            .DocumentUploadLimits::MAX_PORTAL_BYTES_PER_DOSSIER_LABEL
            .'. Remove a file or contact your advisor.',
    );

    expect(UploadedDocument::query()->where('document_request_id', $openRequest->id)->exists())->toBeFalse()
        ->and(UploadedDocument::query()->count())->toBe(1)
        // The rollback is the point: no row, and no OCR run charged for it.
        ->and(Pipeline::query()->count())->toBe(0)
        // The stored blob is compensated by UploadDocumentForRequestAction's catch.
        ->and(Storage::disk('local')->allFiles(sprintf(
            'tenants/%d/dossiers/%d',
            $context['tenant']->id,
            $context['dossier']->id,
        )))
        ->toBeEmpty();
});

test('the write-time cap is summed inside the transaction holding the dossier lock', function (): void {
    Storage::fake('local');
    config(['filesystems.default' => 'local']);
    Queue::fake();

    $context = portalQuotaWorkspace();
    actingInWorkspace($context['tenant']);

    $documentRequest = DocumentRequest::factory()->createOne([
        'tenant_id' => $context['tenant']->id,
        'dossier_id' => $context['dossier']->id,
        'title' => 'Payslip March 2025',
        'status' => DocumentRequestStatus::Pending,
    ]);

    /** @var list<string> $queries */
    $queries = [];

    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    resolve(SubmitPortalUploadAction::class)->handle(
        $documentRequest,
        $context['grant'],
        stagedUpload($context['tenant']->id, 'payslip.pdf', 'application/pdf', 10 * 1024),
    );

    $dossierLockIndex = null;
    $capSumIndex = null;

    foreach ($queries as $index => $sql) {
        $lowered = mb_strtolower($sql);

        if ($dossierLockIndex === null && str_contains($lowered, 'from "dossiers"')) {
            $dossierLockIndex = $index;
        }

        if (str_contains($lowered, 'sum(') && str_contains($lowered, 'from "uploaded_documents"')) {
            $capSumIndex = $index;
        }
    }

    expect($dossierLockIndex)->not->toBeNull('The dossier row was never read — the cap has nothing serialising it.')
        ->and($capSumIndex)->not->toBeNull('The cap was never summed at write time.')
        ->and($dossierLockIndex)->toBeLessThan($capSumIndex);

    if (DB::connection()->getDriverName() === 'pgsql') {
        expect(mb_strtolower($queries[$dossierLockIndex]))->toContain('for update');
    }
});

test('portal upload succeeds when the dossier is still under the byte quota', function (): void {
    Storage::fake('local');
    config(['filesystems.default' => 'local']);
    Queue::fake();

    $context = portalQuotaWorkspace();
    actingInWorkspace($context['tenant']);

    $documentRequest = DocumentRequest::factory()->createOne([
        'tenant_id' => $context['tenant']->id,
        'dossier_id' => $context['dossier']->id,
        'title' => 'Payslip January 2025',
        'status' => DocumentRequestStatus::Pending,
    ]);

    UploadedDocument::factory()->createOne([
        'tenant_id' => $context['tenant']->id,
        'document_request_id' => DocumentRequest::factory()->createOne([
            'tenant_id' => $context['tenant']->id,
            'dossier_id' => $context['dossier']->id,
            'title' => 'Prior upload',
            'status' => DocumentRequestStatus::Submitted,
        ])->id,
        'size_bytes' => 50_000,
    ]);

    $this->get(route('portal.exchange', $context['plainTextToken']));

    $this->post(
        route('portal.document-requests.upload', [
            'documentRequest' => $documentRequest->id,
        ]),
        ['documents' => [stagedUploadTicket($context['tenant']->id, 'payslip.pdf', sizeBytes: 120 * 1024)]],
    )->assertRedirect(route('portal.show'));

    actingInWorkspace($context['tenant']);

    expect(UploadedDocument::query()->where('document_request_id', $documentRequest->id)->exists())->toBeTrue();
});
