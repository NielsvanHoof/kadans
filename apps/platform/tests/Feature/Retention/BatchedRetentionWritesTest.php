<?php

declare(strict_types=1);

use App\Audit\Enums\AuditEvent;
use App\Audit\Models\Activity;
use App\Clients\Models\Client;
use App\Dossiers\Actions\Builder\ReorderDocumentRequestsAction;
use App\Dossiers\Models\DocumentRequest;
use App\Dossiers\Models\Dossier;
use App\Dossiers\Models\UploadedDocument;
use App\Platform\Actions\Retention\PurgeSubjectActivityLogAction;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

covers(PurgeSubjectActivityLogAction::class, ReorderDocumentRequestsAction::class);

/**
 * Both paths issued one statement per row while holding a lock: the purge
 * inside the transaction PurgeExpiredDossierAction opens (up to 50 dossiers per
 * tenant nightly), the reorder inside the dossier's own lockForUpdate on an
 * interactive endpoint.
 *
 * @return list<string>
 */
function statementsMatching(string $prefix, string $table, callable $body): array
{
    /** @var list<string> $matched */
    $matched = [];

    DB::listen(function (QueryExecuted $query) use (&$matched, $prefix, $table): void {
        $sql = mb_strtolower($query->sql);

        if (str_starts_with($sql, $prefix) && str_contains($sql, $table)) {
            $matched[] = $query->sql;
        }
    });

    $body();

    return $matched;
}

test('purging a dossier subtree batches its activity deletes', function (): void {
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();

    $client = Client::factory()->create(['tenant_id' => $tenant->id]);
    $dossier = Dossier::factory()->create(['tenant_id' => $tenant->id, 'client_id' => $client->id]);

    // Twelve children across two subject types, each with its own trail row.
    foreach (range(1, 6) as $index) {
        $documentRequest = DocumentRequest::factory()->create([
            'tenant_id' => $tenant->id,
            'dossier_id' => $dossier->id,
            'sort_order' => $index,
        ]);

        $uploadedDocument = UploadedDocument::factory()->create([
            'tenant_id' => $tenant->id,
            'document_request_id' => $documentRequest->id,
        ]);

        foreach ([$documentRequest, $uploadedDocument] as $subject) {
            Activity::query()->create([
                'log_name' => config('activitylog.default_log_name'),
                'description' => 'Document uploaded',
                'event' => AuditEvent::DocumentUploaded->value,
                'subject_type' => $subject->getMorphClass(),
                'subject_id' => $subject->getKey(),
                'tenant_id' => $tenant->id,
                'causer_type' => $owner->getMorphClass(),
                'causer_id' => $owner->id,
            ]);
        }
    }

    // Client and Dossier both use LogsActivity, so their creation rows are here
    // too. The dossier's own row is in the subtree; the client's is not.
    $subtreeRows = 12;

    expect(Activity::query()->count())->toBe($subtreeRows + 2);

    $deletes = statementsMatching('delete', 'activity_log', function () use ($dossier, $subtreeRows): void {
        $deleted = resolve(PurgeSubjectActivityLogAction::class)->handle($dossier);

        // The count the retention command reports must stay correct.
        expect($deleted)->toBe($subtreeRows + 1);
    });

    // One for the dossier itself plus one per POPULATED child type — exports,
    // audio and pipelines are empty here and now cost no statement at all.
    // The number must track subject types, never row count: twelve child rows
    // must not mean twelve statements.
    expect($deletes)->toHaveCount(3)
        // Only the client's own row survives.
        ->and(Activity::query()->count())->toBe(1);
});

test('reordering document requests writes every position in one statement', function (): void {
    ['tenant' => $tenant] = provisionWorkspace();

    $client = Client::factory()->create(['tenant_id' => $tenant->id]);
    $dossier = Dossier::factory()->create(['tenant_id' => $tenant->id, 'client_id' => $client->id]);

    $documentRequests = collect(range(0, 9))->map(fn (int $index): DocumentRequest => DocumentRequest::factory()->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $dossier->id,
        'sort_order' => $index,
    ]));

    $reversedIds = $documentRequests->pluck('id')->reverse()->values()->all();

    $updates = statementsMatching('update', 'document_requests', function () use ($dossier, $reversedIds): void {
        resolve(ReorderDocumentRequestsAction::class)->handle($dossier, $reversedIds);
    });

    expect($updates)->toHaveCount(1);

    $persisted = DocumentRequest::query()
        ->where('dossier_id', $dossier->id)
        ->orderBy('sort_order')
        ->pluck('id')
        ->all();

    expect($persisted)->toBe($reversedIds);
});

test('reordering still rejects a partial list and leaves the order untouched', function (): void {
    ['tenant' => $tenant] = provisionWorkspace();

    $client = Client::factory()->create(['tenant_id' => $tenant->id]);
    $dossier = Dossier::factory()->create(['tenant_id' => $tenant->id, 'client_id' => $client->id]);

    $documentRequests = collect(range(0, 3))->map(fn (int $index): DocumentRequest => DocumentRequest::factory()->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $dossier->id,
        'sort_order' => $index,
    ]));

    $originalOrder = $documentRequests->pluck('id')->all();

    expect(fn () => resolve(ReorderDocumentRequestsAction::class)
        ->handle($dossier, [$originalOrder[0], $originalOrder[1]]))
        ->toThrow(ValidationException::class)
        ->and(DocumentRequest::query()
            ->where('dossier_id', $dossier->id)
            ->orderBy('sort_order')
            ->pluck('id')
            ->all())
        ->toBe($originalOrder);
});

test('reordering a payload with non-sequential keys still writes contiguous positions', function (): void {
    ['tenant' => $tenant] = provisionWorkspace();

    $client = Client::factory()->create(['tenant_id' => $tenant->id]);
    $dossier = Dossier::factory()->create(['tenant_id' => $tenant->id, 'client_id' => $client->id]);

    $documentRequests = collect(range(0, 2))->map(fn (int $index): DocumentRequest => DocumentRequest::factory()->create([
        'tenant_id' => $tenant->id,
        'dossier_id' => $dossier->id,
        'sort_order' => $index,
    ]));

    $ids = $documentRequests->pluck('id')->reverse()->values()->all();

    // The position in the payload becomes sort_order, so keys that are not
    // 0..n-1 would otherwise write gaps or duplicates.
    resolve(ReorderDocumentRequestsAction::class)->handle($dossier, [
        7 => $ids[0],
        3 => $ids[1],
        9 => $ids[2],
    ]);

    expect(DocumentRequest::query()
        ->where('dossier_id', $dossier->id)
        ->orderBy('sort_order')
        ->pluck('sort_order')
        ->all())->toBe([0, 1, 2]);
});
