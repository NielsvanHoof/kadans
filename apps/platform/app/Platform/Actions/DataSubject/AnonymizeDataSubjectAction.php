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
use App\Platform\Enums\DataSubjectRequestStatus;
use App\Platform\Enums\DataSubjectRequestType;
use App\Platform\Models\DataSubjectRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;

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
    ) {}

    public function handle(DataSubjectRequest $request, User $completedBy): void
    {
        throw_unless(
            $request->type === DataSubjectRequestType::Erasure,
            RuntimeException::class,
            'Only an erasure request anonymizes a client.',
        );

        throw_unless($request->status->isOpen(), ValidationException::withMessages([
            'status' => __('Only an open request can be fulfilled.'),
        ]));

        $client = Client::withTrashed()->findOrFail($request->client_id);

        $dossierIds = [];

        foreach (Dossier::withTrashed()->where('client_id', $client->id)->toBase()->pluck('id') as $id) {
            $dossierIds[] = (int) $id;
        }

        // Outside the transaction: object storage is not transactional, and a
        // rolled-back delete would leave the row pointing at a missing file.
        $archivesDeleted = $this->deleteExportArchives($client);

        DB::transaction(function () use ($request, $client, $completedBy, $dossierIds, $archivesDeleted): void {
            $grantIds = $this->grantIds($dossierIds);

            // Trail first: the grant rows are the subject of their own entries.
            $activityDeleted = $this->purgeActivity($client, $dossierIds, $grantIds);
            $grantsDeleted = $this->deletePortalGrants($dossierIds);

            $this->anonymize($client);

            $request->forceFill([
                'status' => DataSubjectRequestStatus::Completed,
                'completed_at' => now(),
                'completed_by' => $completedBy->id,
                // Any archive this request's own export produced is gone too.
                'disk' => null,
                'path' => null,
                'expires_at' => null,
            ])->save();

            // Recorded against the request, not the client, so it survives the
            // erasure it describes — the firm's proof that it complied.
            $this->logAuditActivity->handle(
                AuditEvent::DataSubjectErasureCompleted,
                $request,
                [
                    'client_id' => $client->id,
                    'dossiers_retained' => count($dossierIds),
                    'portal_grants_deleted' => $grantsDeleted,
                    'activity_entries_deleted' => $activityDeleted,
                    'export_archives_deleted' => $archivesDeleted,
                ],
                causer: $completedBy,
            );
        });
    }

    /**
     * An export archive is a complete copy of the person in one file. Leaving it
     * behind would make the erasure meaningless.
     */
    private function deleteExportArchives(Client $client): int
    {
        $deleted = 0;

        $archiveQuery = DataSubjectRequest::query()->where('client_id', $client->id);
        $archiveQuery->getQuery()->whereNotNull('path');

        $archives = $archiveQuery->get();

        foreach ($archives as $archive) {
            if ($archive->disk === null || $archive->path === null) {
                continue;
            }

            if (Storage::disk($archive->disk)->exists($archive->path)) {
                Storage::disk($archive->disk)->delete($archive->path);
            }

            $archive->forceFill([
                'disk' => null,
                'path' => null,
                'expires_at' => null,
            ])->save();

            $deleted++;
        }

        return $deleted;
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
            $grantActivity = Activity::query()
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
