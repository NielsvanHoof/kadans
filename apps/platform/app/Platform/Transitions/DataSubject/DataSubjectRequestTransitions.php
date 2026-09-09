<?php

declare(strict_types=1);

namespace App\Platform\Transitions\DataSubject;

use App\Accounts\Models\User;
use App\Platform\Enums\DataSubjectRequestStatus;
use App\Platform\Models\DataSubjectRequest;

use function mb_substr;
use function mb_trim;

/**
 * Guarded status transitions for data-subject requests.
 *
 * Flow: pending → completed|refused, and both are terminal — erasure cannot be
 * undone and a refusal is a recorded answer, so nothing reopens a closed
 * request. That makes `pending` the only state either transition may act on.
 *
 * The guard used to be a bare `$request->status->isOpen()` in each Action,
 * checked on the caller-supplied instance before the transaction and never
 * re-read inside it. Two concurrent fulfils both passed, both erased, and both
 * wrote a DataSubjectErasureCompleted entry with a different completed_by — a
 * double erasure with two contradictory records of who performed it. A refuse
 * racing a fulfil could likewise leave a request both Completed and Refused.
 *
 * lockIfOpen() is the fix and every caller must go through it.
 *
 * Writes are plain saves rather than saveQuietly: DataSubjectRequest does not
 * use LogsActivity, so these were never activity-logged. The business events
 * (erasure completed, request refused) are logged explicitly by the calling
 * Action via LogAuditActivityAction, inside the same transaction.
 */
final class DataSubjectRequestTransitions
{
    /**
     * Whether the request is still open to be fulfilled or refused.
     */
    public function canClose(DataSubjectRequest $request): bool
    {
        return $request->status->isOpen();
    }

    /**
     * Lock the request row and re-read its status, returning null when someone
     * else has already closed it.
     *
     * Two contracts callers must honour, neither of which this method can
     * enforce for them:
     *
     * 1. Be inside a transaction already. A row lock taken outside one is
     *    released immediately and guards nothing. The lock also has to be held
     *    for the whole erasure, not just the status write, which is why this
     *    takes the lock but does not open the transaction itself.
     * 2. Keep using the returned instance, not the one passed in. The caller's
     *    copy is the stale read this exists to defend against.
     */
    public function lockIfOpen(DataSubjectRequest $request): ?DataSubjectRequest
    {
        $requestQuery = DataSubjectRequest::query()->whereKey($request->getKey());
        $requestQuery->getQuery()->lockForUpdate();

        $locked = $requestQuery->firstOrFail();

        return $this->canClose($locked) ? $locked : null;
    }

    /**
     * Close a request as fulfilled.
     *
     * The archive columns are cleared here too: whatever export this request
     * produced is deleted as part of the erasure, so leaving disk/path set
     * would advertise a file that no longer exists.
     */
    public function complete(DataSubjectRequest $request, User $completedBy): void
    {
        $request->forceFill([
            'status' => DataSubjectRequestStatus::Completed,
            'completed_at' => now(),
            'completed_by' => $completedBy->id,
            'disk' => null,
            'path' => null,
            'expires_at' => null,
        ])->save();
    }

    /**
     * Close a request as refused, with the reason on record.
     */
    public function refuse(DataSubjectRequest $request, User $refusedBy, string $reason): void
    {
        $request->forceFill([
            'status' => DataSubjectRequestStatus::Refused,
            'refused_at' => now(),
            'refused_by' => $refusedBy->id,
            'refusal_reason' => mb_substr(mb_trim($reason), 0, 500),
        ])->save();
    }
}
