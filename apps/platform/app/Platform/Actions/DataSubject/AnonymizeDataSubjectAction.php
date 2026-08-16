<?php

declare(strict_types=1);

namespace App\Platform\Actions\DataSubject;

use App\Accounts\Models\User;
use App\Audit\Actions\LogAuditActivityAction;
use App\Audit\Enums\AuditEvent;
use App\Audit\Models\Activity;
use App\ClientPortal\Models\ClientAccessGrant;
use App\Clients\Models\Client;
use App\Dossiers\Models\Dossier;
use App\Platform\Actions\Retention\PurgeSubjectActivityLogAction;
use App\Platform\Enums\DataSubjectRequestType;
use App\Platform\Models\DataSubjectRequest;
use App\Platform\Transitions\DataSubjectRequestTransitions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

use function count;
use function sprintf;

/**
 * Fulfil an erasure request by removing the client's identity while keeping the
 * records the firm is legally obliged to retain.
 *
 * Erasure is not deletion here, and pretending otherwise would be dishonest.
 * A Dutch accounting firm must keep its financial administration for roughly
 * seven years, which GDPR Art. 17(3)(b) explicitly permits as an exception to
 * the right to erasure. What can go is the *directory* data that makes those
 * records attributable: the client record's name and email, portal credentials
 * and the device history attached to them, any export archive already produced,
 * and the activity trail for the client and its dossiers.
 *
 * What stays: dossiers, uploaded documents, audio and transcripts. Those are the
 * retained records. Their contents may still name the person — a payslip is a
 * payslip — and no amount of field-level anonymisation changes that. The
 * response to the data subject has to say so.
 */
final readonly class AnonymizeDataSubjectAction
{
    /**
     * `.invalid` is reserved by RFC 2606 and can never resolve, so a stale
     * placeholder address cannot accidentally reach a real mailbox.
     */
    private const string ERASED_EMAIL_FORMAT = 'erased-%d@erased.invalid';

    private const string ERASED_NAME_FORMAT = 'Erased client #%d';

    public function __construct(
        private LogAuditActivityAction $logAuditActivity,
        private PurgeSubjectActivityLogAction $purgeSubjectActivityLog,
        private DataSubjectRequestTransitions $transitions,
    ) {}

    public function handle(DataSubjectRequest $request, User $completedBy): void
    {
        throw_unless(
            $request->type === DataSubjectRequestType::Erasure,
            RuntimeException::class,
            'Only an erasure request anonymizes a client.',
        );

        // Cheap pre-check so a plainly-closed request fails without opening a
        // transaction. It is not the guard — see lockIfOpen() below.
        throw_unless($this->transitions->canClose($request), ValidationException::withMessages([
            'status' => __('Only an open request can be fulfilled.'),
        ]));

        $archives = DB::transaction(function () use ($request, $completedBy): array {
            // Everything below runs on the locked row. A double-submit that gets
            // here second finds the request already Completed and is rejected
            // rather than erasing a second time under a different completed_by.
            $locked = $this->transitions->lockIfOpen($request);

            throw_unless($locked instanceof DataSubjectRequest, ValidationException::withMessages([
                'status' => __('Only an open request can be fulfilled.'),
            ]));

            $client = Client::withTrashed()->findOrFail($locked->client_id);

            $dossierIds = [];

            foreach (Dossier::withTrashed()->where('client_id', $client->id)->toBase()->pluck('id') as $id) {
                $dossierIds[] = (int) $id;
            }

            $grantIds = $this->grantIds($dossierIds);

            // Trail first: the grant rows are the subject of their own entries.
            $activityDeleted = $this->purgeActivity($client, $dossierIds, $grantIds);
            $grantsDeleted = $this->deletePortalGrants($dossierIds);
            $archives = $this->clearExportArchiveRows($client);

            $this->anonymize($client);

            $this->transitions->complete($locked, $completedBy);

            // Recorded against the request, not the client, so it survives the
            // erasure it describes — the firm's proof that it complied.
            $this->logAuditActivity->handle(
                AuditEvent::DataSubjectErasureCompleted,
                $locked,
                [
                    'client_id' => $client->id,
                    'dossiers_retained' => count($dossierIds),
                    'portal_grants_deleted' => $grantsDeleted,
                    'activity_entries_deleted' => $activityDeleted,
                    'export_archives_deleted' => count($archives),
                ],
                causer: $completedBy,
            );

            return $archives;
        });

        // Blobs only after a successful commit — mirrors document delete
        // ordering. Deleting them before the transaction, as this used to, had
        // the compensation backwards: a rollback left the file already gone.
        $this->deleteExportArchiveBlobs($archives);
    }

    /**
     * Clear the archive columns for every request belonging to this client, and
     * report the objects that now need deleting.
     *
     * An export archive is a complete copy of the person in one file, so leaving
     * one behind would make the erasure meaningless. The row write belongs in
     * the transaction with the status change; the blob delete cannot be, because
     * object storage does not roll back.
     *
     * @return list<array{disk: string, path: string}>
     */
    private function clearExportArchiveRows(Client $client): array
    {
        $archiveQuery = DataSubjectRequest::query()->where('client_id', $client->id);
        $archiveQuery->getQuery()->whereNotNull('path');

        $objects = [];

        foreach ($archiveQuery->get() as $archive) {
            if ($archive->disk === null) {
                continue;
            }
            if ($archive->path === null) {
                continue;
            }

            $objects[] = ['disk' => $archive->disk, 'path' => $archive->path];

            $archive->forceFill([
                'disk' => null,
                'path' => null,
                'expires_at' => null,
            ])->save();
        }

        return $objects;
    }

    /**
     * A failed archive delete must not pass silently: the row now says the copy
     * is gone, so an unreported failure is a false erasure record.
     *
     * @param  list<array{disk: string, path: string}>  $archives
     */
    private function deleteExportArchiveBlobs(array $archives): void
    {
        foreach ($archives as $archive) {
            try {
                if (! Storage::disk($archive['disk'])->exists($archive['path'])) {
                    continue;
                }

                $deleted = Storage::disk($archive['disk'])->delete($archive['path']);
            } catch (Throwable $exception) {
                report(new RuntimeException(
                    'Failed to delete a data-subject export archive during erasure.',
                    previous: $exception,
                ));

                continue;
            }

            report_unless($deleted, new RuntimeException(
                'Failed to delete a data-subject export archive during erasure.',
            ));
        }
    }

    /**
     * @param  list<int>  $dossierIds
     * @return list<int>
     */
    private function grantIds(array $dossierIds): array
    {
        if ($dossierIds === []) {
            return [];
        }

        $query = ClientAccessGrant::query();
        $query->getQuery()->whereIn('dossier_id', $dossierIds);

        $ids = [];

        foreach ($query->toBase()->pluck('id') as $id) {
            $ids[] = (int) $id;
        }

        return $ids;
    }

    /**
     * @param  list<int>  $dossierIds
     */
    private function deletePortalGrants(array $dossierIds): int
    {
        if ($dossierIds === []) {
            return 0;
        }

        $query = ClientAccessGrant::query();
        $query->getQuery()->whereIn('dossier_id', $dossierIds);

        return $query->toBase()->delete();
    }

    /**
     * Remove the person's trail rather than editing it.
     *
     * `Activity` blocks updates outright — `booted()` throws on `updating` — so
     * redacting a row in place is not possible by design. Deletion is the
     * sanctioned operation, and the same PurgeSubjectActivityLogAction the
     * scheduled retention purge uses does it: client rows carry the old name and
     * email in `attribute_changes`, and dossier-subtree rows carry the client's
     * IP and user agent from portal requests.
     *
     * The trade-off is deliberate: the firm loses the access trail for these
     * dossiers, but the erasure entry recorded against the request keeps a
     * dated, attributed record that it happened and how much it covered.
     *
     * @param  list<int>  $dossierIds
     * @param  list<int>  $grantIds
     */
    private function purgeActivity(Client $client, array $dossierIds, array $grantIds): int
    {
        $deleted = $this->purgeSubjectActivityLog->handle($client);

        $dossierQuery = Dossier::withTrashed();
        $dossierQuery->getQuery()->whereIn('id', $dossierIds);

        foreach ($dossierQuery->get() as $dossier) {
            $deleted += $this->purgeSubjectActivityLog->handle($dossier);
        }

        if ($grantIds !== []) {
            $grantActivity = Activity::queryAcrossTenants()
                ->where('subject_type', (new ClientAccessGrant)->getMorphClass());
            $grantActivity->getQuery()->whereIn('subject_id', $grantIds);

            $deleted += $grantActivity->toBase()->delete();
        }

        return $deleted;
    }

    /**
     * `disableLogging()` is load-bearing: without it the save writes an activity
     * row whose `attribute_changes.old` holds the real name and email.
     */
    private function anonymize(Client $client): void
    {
        $client->disableLogging();

        $client->forceFill([
            'name' => sprintf(self::ERASED_NAME_FORMAT, $client->id),
            'email' => sprintf(self::ERASED_EMAIL_FORMAT, $client->id),
        ])->save();

        // Scout indexes name and email (toSearchableArray); re-index so the old
        // values stop being searchable.
        $client->searchable();
    }
}
