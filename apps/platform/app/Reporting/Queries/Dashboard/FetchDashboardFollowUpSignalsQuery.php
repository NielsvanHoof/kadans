<?php

declare(strict_types=1);

namespace App\Reporting\Queries\Dashboard;

use App\ClientPortal\Models\ClientAccessGrant;
use App\Clients\Models\Client;
use App\Dossiers\Enums\DocumentRequestStatus;
use App\Dossiers\Enums\DossierStatus;
use App\Dossiers\Models\Dossier;
use App\Reporting\Data\Dashboard\DashboardFollowUpItemData;
use App\Reporting\Data\Dashboard\DashboardFollowUpSignalsData;
use App\Tenancy\Models\Tenant;
use Illuminate\Contracts\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Config;

use function count;

/**
 * Where the client side of the workflow has stalled.
 *
 * Never memoised: RecordClientPortalAccessAction writes last_client_opened_at
 * with a base-query update, bypassing model events, so a cached copy would keep
 * flagging a client who has already opened their link. Deferred and live means
 * these are always current.
 */
final class FetchDashboardFollowUpSignalsQuery
{
    /** Rows shown per group; the group count carries the rest. */
    private const int GROUP_LIMIT = 5;

    public function handle(Tenant $tenant): DashboardFollowUpSignalsData
    {
        $neverOpened = $this->neverOpened($tenant);
        $goneQuiet = $this->goneQuiet($tenant);
        $expiringLinks = $this->expiringLinks($tenant);

        return new DashboardFollowUpSignalsData(
            neverOpened: $neverOpened,
            goneQuiet: $goneQuiet,
            expiringLinks: $expiringLinks,
            totalCount: count($neverOpened) + count($goneQuiet) + count($expiringLinks),
        );
    }

    /**
     * Invited, but the portal link has never been opened.
     *
     * @return list<DashboardFollowUpItemData>
     */
    private function neverOpened(Tenant $tenant): array
    {
        $threshold = now()->subDays(Config::integer('portal.follow_up.never_opened_days'));

        $query = $this->openDossierQuery($tenant)->with(['client:id,name']);

        $query->getQuery()
            ->whereNotNull('last_client_message_sent_at')
            ->whereNull('last_client_opened_at')
            ->where('last_client_message_sent_at', '<=', $threshold)
            // Longest-waiting client first.
            ->oldest('last_client_message_sent_at')
            ->limit(self::GROUP_LIMIT);

        return $this->mapDossiers($query, 'last_client_message_sent_at');
    }

    /**
     * Opened once, then nothing — while documents are still outstanding.
     *
     * @return list<DashboardFollowUpItemData>
     */
    private function goneQuiet(Tenant $tenant): array
    {
        $threshold = now()->subDays(Config::integer('portal.follow_up.stale_activity_days'));

        $query = $this->openDossierQuery($tenant)
            ->with(['client:id,name'])
            ->whereHas('documentRequests', fn (QueryBuilder $documentRequestQuery) => $documentRequestQuery
                ->getQuery()
                ->whereIn('status', DocumentRequestStatus::awaitingClientValues()));

        $query->getQuery()
            ->whereNotNull('last_client_opened_at')
            ->where('last_client_opened_at', '<=', $threshold)
            // Quietest client first.
            ->oldest('last_client_opened_at')
            ->limit(self::GROUP_LIMIT);

        return $this->mapDossiers($query, 'last_client_opened_at');
    }

    /**
     * Live grants about to lapse, so staff can reissue before the client is
     * locked out.
     *
     * @return list<DashboardFollowUpItemData>
     */
    private function expiringLinks(Tenant $tenant): array
    {
        $horizon = now()->addDays(Config::integer('portal.follow_up.expiring_link_days'));

        $query = ClientAccessGrant::query()
            ->whereBelongsTo($tenant)
            // Also drops grants whose dossier was soft-deleted.
            ->whereHas('dossier', function (QueryBuilder $dossierQuery): void {
                $dossierQuery->getQuery()->whereNotIn('status', DossierStatus::lockedValues());
            })
            ->with(['dossier:id,title,client_id', 'dossier.client:id,name']);

        $query->getQuery()
            ->whereNull('revoked_at')
            ->whereNotNull('expires_at')
            ->whereBetween('expires_at', [now(), $horizon])
            // Soonest to lapse first.
            ->oldest('expires_at')
            ->limit(self::GROUP_LIMIT);

        /** @var list<DashboardFollowUpItemData> $rows */
        $rows = [];

        foreach ($query->get() as $grant) {
            $dossier = $grant->dossier;

            if (! $dossier instanceof Dossier) {
                continue;
            }

            $client = $dossier->client;
            $expiresAt = $grant->expires_at;

            $rows[] = new DashboardFollowUpItemData(
                id: $dossier->id,
                title: $dossier->title,
                clientName: $client instanceof Client ? $client->name : '',
                since: $expiresAt?->toIso8601String(),
                // Days *until* expiry, so the UI can say "expires in 2 days".
                days: $expiresAt === null ? null : (int) today()->diffInDays($expiresAt, false),
            );
        }

        return $rows;
    }

    /**
     * Open, non-locked dossiers for this tenant. Fresh builder per call so the
     * three groups cannot share predicates.
     *
     * @return Builder<Dossier>
     */
    private function openDossierQuery(Tenant $tenant): Builder
    {
        $query = Dossier::query()->whereBelongsTo($tenant);

        $query->getQuery()->whereNotIn('status', DossierStatus::lockedValues());

        return $query;
    }

    /**
     * @param  Builder<Dossier>  $query
     * @param  'last_client_message_sent_at'|'last_client_opened_at'  $sinceColumn
     * @return list<DashboardFollowUpItemData>
     */
    private function mapDossiers(Builder $query, string $sinceColumn): array
    {
        /** @var list<DashboardFollowUpItemData> $rows */
        $rows = [];

        foreach ($query->get(['id', 'client_id', 'title', $sinceColumn]) as $dossier) {
            $client = $dossier->client;
            $since = $dossier->{$sinceColumn};

            $rows[] = new DashboardFollowUpItemData(
                id: $dossier->id,
                title: $dossier->title,
                clientName: $client instanceof Client ? $client->name : '',
                since: $since?->toIso8601String(),
                // Positive: how long the client has been silent.
                days: $since === null ? null : (int) $since->diffInDays(now()),
            );
        }

        return $rows;
    }
}
