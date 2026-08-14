<?php

declare(strict_types=1);

namespace App\Dossiers\Support;

use App\Dossiers\Models\DocumentRequest;
use App\Dossiers\Models\Dossier;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * The single place that takes dossier and document-request row locks.
 *
 * Canonical order, parent before child:
 *
 *     dossiers -> document_requests -> client_access_grants
 *
 * Staff completing a dossier used to lock dossier-then-item while a client
 * submitting the last answer locked item-then-dossier, so the two could
 * deadlock (SQLSTATE 40P01) and 500 for whichever Postgres chose as victim.
 * Nothing retried, because no DB::transaction() call in those paths passed
 * $attempts — and retrying is the wrong lever anyway: the upload closures
 * dispatch pipelines and delete files, so re-running one is worse than never
 * forming the cycle.
 *
 * The order is enforced by tests/Unit/DossierLockOrderTest.php, which is what
 * makes it safe: two of the four original violators did not lock the dossier in
 * their own file at all, but inside a callback that UploadDocumentForRequestAction
 * invoked after it had already locked the item. Reading either file alone showed
 * the wrong order, so a convention nobody can see was never going to hold.
 *
 * Client access grants are locked last, in ClientPortal's own actions: this
 * class cannot reach ClientAccessGrant because Dossiers is L4 and ClientPortal
 * is L5 (.ai/rules/app.md). The order test covers that hop instead.
 *
 * Lives in Support rather than Actions for the same reason PipelineState does:
 * it is a set of related locking operations, not one handle().
 */
final class DossierLocks
{
    /**
     * Lock a dossier row — always the first lock a transaction takes.
     */
    public function dossier(Dossier|int $dossier): Dossier
    {
        $dossierQuery = Dossier::query()
            ->whereKey($dossier instanceof Dossier ? $dossier->getKey() : $dossier);
        $dossierQuery->getQuery()->lockForUpdate();

        return $dossierQuery->firstOrFail();
    }

    /**
     * Lock a dossier row including a soft-deleted one, for restore.
     */
    public function trashedDossier(Dossier|int $dossier): Dossier
    {
        $dossierQuery = Dossier::withTrashed()
            ->whereKey($dossier instanceof Dossier ? $dossier->getKey() : $dossier);
        $dossierQuery->getQuery()->lockForUpdate();

        return $dossierQuery->firstOrFail();
    }

    /**
     * Lock a document request together with its dossier, dossier first.
     *
     * Callers hold a request they resolved outside the transaction, so the
     * parent is read from that unlocked instance. dossier_id is immutable — a
     * request is never moved between dossiers — but the pair is re-checked
     * after both locks are held so a stale instance cannot leave a caller
     * operating on someone else's dossier.
     *
     * @return array{Dossier, DocumentRequest}
     */
    public function documentRequestWithDossier(DocumentRequest $documentRequest): array
    {
        $dossier = $this->dossier($documentRequest->dossier_id);
        $lockedDocumentRequest = $this->documentRequest($documentRequest);

        if ($lockedDocumentRequest->dossier_id !== $dossier->getKey()) {
            throw (new ModelNotFoundException)->setModel(
                DocumentRequest::class,
                [$lockedDocumentRequest->getKey()],
            );
        }

        return [$dossier, $lockedDocumentRequest];
    }

    /**
     * Lock every document request on an already-locked dossier, in builder order.
     *
     * @return Collection<int, DocumentRequest>
     */
    public function documentRequestsFor(Dossier $dossier): Collection
    {
        $documentRequestQuery = DocumentRequest::query()
            ->whereBelongsTo($dossier)
            ->oldest('sort_order')
            ->oldest('id');
        $documentRequestQuery->getQuery()->lockForUpdate();

        return $documentRequestQuery->get();
    }

    /**
     * Lock a single document request.
     *
     * Only safe on its own when the transaction never touches the dossier row:
     * a transaction that locks both must go through documentRequestWithDossier().
     */
    public function documentRequest(DocumentRequest|int $documentRequest): DocumentRequest
    {
        $documentRequestQuery = DocumentRequest::query()
            ->whereKey($documentRequest instanceof DocumentRequest ? $documentRequest->getKey() : $documentRequest);
        $documentRequestQuery->getQuery()->lockForUpdate();

        return $documentRequestQuery->firstOrFail();
    }
}
