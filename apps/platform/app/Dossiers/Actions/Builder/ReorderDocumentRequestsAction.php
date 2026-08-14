<?php

declare(strict_types=1);

namespace App\Dossiers\Actions\Builder;

use App\Dossiers\Models\DocumentRequest;
use App\Dossiers\Models\Dossier;
use App\Dossiers\Support\DossierLocks;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

use function array_diff;
use function array_fill;
use function array_unique;
use function array_values;
use function count;
use function implode;
use function sprintf;
use function str_repeat;

final readonly class ReorderDocumentRequestsAction
{
    public function __construct(
        private DossierLocks $dossierLocks,
    ) {}

    /**
     * @param  array<mixed>  $documentRequestIds
     */
    public function handle(Dossier $dossier, array $documentRequestIds): void
    {
        // array_values before array_map: the position in this list becomes the
        // sort_order, so a payload arriving with non-sequential keys would
        // otherwise write gaps or duplicates.
        $ids = array_map(
            static fn (mixed $id): int => (int) $id,
            array_values($documentRequestIds),
        );

        DB::transaction(function () use ($dossier, $ids): void {
            $lockedDossier = $this->dossierLocks->dossier($dossier);

            $ownedIds = $lockedDossier->documentRequests()
                ->toBase()
                ->pluck('id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->all();

            if (count($ids) !== count(array_unique($ids))
                || count($ids) !== count($ownedIds)
                || array_diff($ids, $ownedIds) !== []
                || array_diff($ownedIds, $ids) !== []) {
                throw ValidationException::withMessages([
                    'document_request_ids' => 'Submit every document request in this dossier exactly once.',
                ]);
            }

            $this->applySortOrder($ids);
        });
    }

    /**
     * Write every new position in one statement.
     *
     * This runs while the dossier row is locked, so a 60-item drag-and-drop
     * used to hold that lock across 60 round trips on an interactive endpoint.
     * A single CASE also removes the window DocumentRequest documents, where
     * two items briefly share a sort_order midway through the loop.
     *
     * The query builder cannot bind parameters inside an UPDATE SET
     * expression, so this drops to a bound statement rather than interpolating
     * values into DB::raw(). Every id and position travels as a placeholder,
     * which is why no escaping question arises at all. Only the table name is
     * composed, and it comes from the model.
     *
     * updated_at is set explicitly because a bound statement does not go
     * through Eloquent's timestamp handling, and the per-row update it
     * replaces did touch it.
     *
     * @param  list<int>  $ids
     */
    private function applySortOrder(array $ids): void
    {
        $bindings = [];

        foreach ($ids as $index => $id) {
            $bindings[] = $id;
            $bindings[] = $index;
        }

        $bindings[] = now();

        foreach ($ids as $id) {
            $bindings[] = $id;
        }

        DB::update(
            sprintf(
                'update %s set sort_order = case id%s end, updated_at = ? where id in (%s)',
                new DocumentRequest()->getTable(),
                str_repeat(' when ? then ?', count($ids)),
                implode(',', array_fill(0, count($ids), '?')),
            ),
            $bindings,
        );
    }
}
