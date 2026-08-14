<?php

declare(strict_types=1);

use App\Clients\Models\Client;
use App\Platform\Enums\DataSubjectRequestStatus;
use App\Platform\Models\DataSubjectRequest;
use App\Platform\Transitions\DataSubjectRequestTransitions;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

covers(DataSubjectRequestTransitions::class);

/**
 * @return array{owner: mixed, tenant: mixed, request: DataSubjectRequest}
 */
function transitionsFixture(DataSubjectRequestStatus $status = DataSubjectRequestStatus::Pending): array
{
    ['owner' => $owner, 'tenant' => $tenant] = provisionWorkspace();

    $client = Client::factory()->create(['tenant_id' => $tenant->id]);

    $request = DataSubjectRequest::factory()->erasure()->create([
        'tenant_id' => $tenant->id,
        'client_id' => $client->id,
        'requested_by' => $owner->id,
        'status' => $status,
    ]);

    return ['owner' => $owner, 'tenant' => $tenant, 'request' => $request];
}

describe('canClose', function (): void {
    test('only a pending request is open to be closed', function (DataSubjectRequestStatus $status, bool $expected): void {
        ['request' => $request] = transitionsFixture($status);

        expect(resolve(DataSubjectRequestTransitions::class)->canClose($request))->toBe($expected);
    })->with([
        'pending' => [DataSubjectRequestStatus::Pending, true],
        // Both closed states are terminal: erasure cannot be undone and a
        // refusal is a recorded answer, so neither reopens.
        'completed' => [DataSubjectRequestStatus::Completed, false],
        'refused' => [DataSubjectRequestStatus::Refused, false],
    ]);
});

describe('lockIfOpen', function (): void {
    test('an open request is returned as a locked row', function (): void {
        ['request' => $request] = transitionsFixture();

        $locked = DB::transaction(
            fn (): ?DataSubjectRequest => resolve(DataSubjectRequestTransitions::class)->lockIfOpen($request),
        );

        expect($locked)->toBeInstanceOf(DataSubjectRequest::class)
            ->and($locked?->getKey())->toBe($request->getKey());
    });

    test('a request closed by someone else returns null instead of the caller instance', function (): void {
        ['request' => $request] = transitionsFixture();

        // The caller's instance still reads Pending. This is exactly the stale
        // read the old guard trusted: it checked the in-memory copy, so a
        // second submit erased again under a different completed_by.
        DataSubjectRequest::query()
            ->whereKey($request->getKey())
            ->update(['status' => DataSubjectRequestStatus::Completed->value]);

        expect($request->status)->toBe(DataSubjectRequestStatus::Pending);

        $locked = DB::transaction(
            fn (): ?DataSubjectRequest => resolve(DataSubjectRequestTransitions::class)->lockIfOpen($request),
        );

        expect($locked)->toBeNull();
    });

    test('the lock is taken on the request row', function (): void {
        ['request' => $request] = transitionsFixture();

        /** @var list<string> $lockQueries */
        $lockQueries = [];

        DB::listen(function (QueryExecuted $query) use (&$lockQueries): void {
            $sql = mb_strtolower($query->sql);

            if (str_contains($sql, 'from "data_subject_requests"')) {
                $lockQueries[] = $query->sql;
            }
        });

        DB::transaction(
            fn (): ?DataSubjectRequest => resolve(DataSubjectRequestTransitions::class)->lockIfOpen($request),
        );

        expect($lockQueries)->not->toBeEmpty();

        // SQLite compiles lockForUpdate() to nothing, so the SQL can only be
        // asserted on the real driver — same approach as
        // tests/Feature/Tenancy/LastOwnerMutationLockTest.php.
        if (DB::connection()->getDriverName() === 'pgsql') {
            expect(mb_strtolower($lockQueries[0]))->toContain('for update');
        }
    });

});

describe('closing a request', function (): void {
    test('complete records who fulfilled it and clears the archive columns', function (): void {
        Storage::fake('local');

        ['owner' => $owner, 'request' => $request] = transitionsFixture();

        $request->forceFill([
            'disk' => 'local',
            'path' => 'exports/subject.zip',
            'expires_at' => now()->addHours(72),
        ])->save();

        resolve(DataSubjectRequestTransitions::class)->complete($request, $owner);

        $request->refresh();

        expect($request->status)->toBe(DataSubjectRequestStatus::Completed)
            ->and($request->completed_by)->toBe($owner->id)
            ->and($request->completed_at)->not->toBeNull()
            // The archive is destroyed by the erasure, so advertising it would lie.
            ->and($request->disk)->toBeNull()
            ->and($request->path)->toBeNull()
            ->and($request->expires_at)->toBeNull();
    });

    test('refuse records the reason and truncates it to the column width', function (): void {
        ['owner' => $owner, 'request' => $request] = transitionsFixture();

        resolve(DataSubjectRequestTransitions::class)
            ->refuse($request, $owner, '  '.str_repeat('a', 600).'  ');

        $request->refresh();

        expect($request->status)->toBe(DataSubjectRequestStatus::Refused)
            ->and($request->refused_by)->toBe($owner->id)
            ->and($request->refused_at)->not->toBeNull()
            ->and($request->refusal_reason)->toBe(str_repeat('a', 500));
    });
});
