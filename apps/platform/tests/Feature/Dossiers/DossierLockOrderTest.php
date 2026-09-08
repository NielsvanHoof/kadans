<?php

declare(strict_types=1);

use App\Accounts\Models\User;
use App\Clients\Models\Client;
use App\Dossiers\Actions\DocumentRequests\ReviewDocumentRequestAction;
use App\Dossiers\Actions\DocumentRequests\SubmitStaffQuestionnaireAnswerAction;
use App\Dossiers\Actions\Lifecycle\CompleteDossierAction;
use App\Dossiers\Actions\UploadedDocuments\UploadStaffDocumentAction;
use App\Dossiers\Enums\DocumentRequestStatus;
use App\Dossiers\Enums\DossierStatus;
use App\Dossiers\Models\DocumentRequest;
use App\Dossiers\Models\Dossier;
use App\Dossiers\Support\DossierLocks;
use App\Dossiers\Transitions\Lifecycle\DossierTransitions;
use App\Questionnaires\Enums\QuestionnaireItemType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

covers(DossierLocks::class);

/*
|--------------------------------------------------------------------------
| Runtime lock order
|--------------------------------------------------------------------------
|
| tests/Unit/DossierLockOrderTest.php proves the source takes locks in
| canonical order. This file proves the database sees them that way.
|
| The suite runs on SQLite, whose grammar compiles `lockForUpdate()` to nothing,
| so the emitted SQL carries no "for update" to assert on. The locking read is
| still a real query though, and its position in the query log is the order in
| which Postgres would acquire the locks. That is what these tests assert, and
| it is the one check that sees through UploadDocumentForRequestAction's
| callback — where the original bug hid, and where a source scan cannot follow.
|
| A true SQLSTATE 40P01 reproduction needs two concurrent Postgres sessions and
| is not something a single-connection SQLite suite can express; that was
| verified out-of-band against the dev Postgres instead.
|
*/

/**
 * The order in which the given operation first reads each ranked table.
 *
 * @return list<string>
 */
function lockedTableOrder(Closure $operation): array
{
    /** @var list<string> $tables */
    $tables = [];

    DB::listen(function ($query) use (&$tables): void {
        foreach (['dossiers', 'document_requests', 'client_access_grants'] as $table) {
            // Only the first touch matters: re-reading a table you already hold
            // a lock on cannot change the acquisition order.
            if (str_contains($query->sql, "\"{$table}\"") && ! in_array($table, $tables, true)) {
                $tables[] = $table;
            }
        }
    });

    $operation();

    return $tables;
}

beforeEach(function (): void {
    ['tenant' => $tenant] = provisionWorkspace();
    $this->tenant = $tenant;
    $this->staff = User::query()->firstOrFail();

    $client = Client::factory()->create(['tenant_id' => $tenant->id]);
    $this->dossier = Dossier::factory()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
        'status' => DossierStatus::AwaitingClient,
    ]);
});

test('completing a dossier reads the dossier before its document requests', function (): void {
    DocumentRequest::factory()->create([
        'tenant_id' => $this->tenant->id,
        'dossier_id' => $this->dossier->id,
        'type' => QuestionnaireItemType::Boolean,
        'status' => DocumentRequestStatus::Accepted,
    ]);

    resolve(DossierTransitions::class)->markInReview($this->dossier);

    $order = lockedTableOrder(fn () => resolve(CompleteDossierAction::class)
        ->handle($this->dossier, $this->staff));

    expect($order)->toBe(['dossiers', 'document_requests']);
});

test('a staff answer reads the dossier before the document request', function (): void {
    $documentRequest = DocumentRequest::factory()->create([
        'tenant_id' => $this->tenant->id,
        'dossier_id' => $this->dossier->id,
        'type' => QuestionnaireItemType::Boolean,
        'status' => DocumentRequestStatus::Pending,
    ]);

    $order = lockedTableOrder(fn () => resolve(SubmitStaffQuestionnaireAnswerAction::class)
        ->handle($documentRequest, answerBoolean: true));

    // Before the fix this was ['document_requests', 'dossiers'] — the exact
    // reverse of the completion path above, which is the deadlock.
    expect($order)->toBe(['dossiers', 'document_requests']);
});

test('a staff upload reads the dossier before the document request', function (): void {
    Storage::fake(config()->string('filesystems.default'));

    $documentRequest = DocumentRequest::factory()->create([
        'tenant_id' => $this->tenant->id,
        'dossier_id' => $this->dossier->id,
        'type' => QuestionnaireItemType::File,
        'status' => DocumentRequestStatus::Pending,
    ]);

    // The upload path is the one a source scan cannot check: the dossier lock
    // used to live in UploadStaffDocumentAction's callback, which runs after
    // UploadDocumentForRequestAction has already locked the item.
    $order = lockedTableOrder(fn () => resolve(UploadStaffDocumentAction::class)
        ->handle($documentRequest, stagedUpload($documentRequest->tenant_id, 'payslip.pdf', 'application/pdf', 16 * 1024)));

    expect($order)->toBe(['dossiers', 'document_requests']);
});

test('reviewing a document request never reaches for the dossier row', function (): void {
    $documentRequest = DocumentRequest::factory()->create([
        'tenant_id' => $this->tenant->id,
        'dossier_id' => $this->dossier->id,
        'type' => QuestionnaireItemType::Boolean,
        'status' => DocumentRequestStatus::Submitted,
    ]);

    $order = lockedTableOrder(fn () => resolve(ReviewDocumentRequestAction::class)
        ->handle($documentRequest, $this->staff, DocumentRequestStatus::Accepted));

    // Locking one of the pair is safe on its own; it is holding both in the
    // wrong order that deadlocks. Asserting the dossier is absent keeps a
    // future change from quietly adding the second half of a cycle here.
    expect($order)->toBe(['document_requests']);
});
