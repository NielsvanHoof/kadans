<?php

declare(strict_types=1);

namespace App\Reporting\Queries;

use App\Accounts\Models\User;
use App\Clients\Models\Client;
use App\Dossiers\Enums\DocumentRequestStatus;
use App\Dossiers\Enums\DossierStatus;
use App\Dossiers\Models\Dossier;
use App\Reporting\Data\DashboardAgendaItemData;
use App\Reporting\Data\DashboardMyWorkData;
use App\Tenancy\Models\Tenant;
use Illuminate\Contracts\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;

/**
 * The signed-in user's own slice of the workspace.
 *
 * Never memoised: WorkspaceDashboardCache is keyed by tenant and date with no
 * user segment, so caching these counters would serve one member's queue to the
 * whole firm. Kept cheap instead — four indexed counts plus one short list,
 * served by dossiers_tenant_responsible_due_index.
 */
final class FetchDashboardMyWorkQuery
{
    /** Days ahead the agenda looks. */
    private const int AGENDA_DAYS = 7;

    /** Rows on the agenda list; the counters above it cover the rest. */
    private const int AGENDA_LIMIT = 6;

    public function handle(Tenant $tenant, User $user): DashboardMyWorkData
    {
        $horizon = today()->addDays(self::AGENDA_DAYS);

        $dueThisWeekQuery = $this->assignedOpenQuery($tenant, $user);
        $dueThisWeekQuery->getQuery()
            ->whereNotNull('due_date')
            ->whereBetween('due_date', [today(), $horizon]);

        $overdueQuery = $this->assignedOpenQuery($tenant, $user);
        $overdueQuery->getQuery()
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<', today());

        $awaitingReviewQuery = $this->assignedOpenQuery($tenant, $user)
            ->whereHas('documentRequests', fn (QueryBuilder $documentRequestQuery) => $documentRequestQuery
                ->getQuery()
                ->where('status', DocumentRequestStatus::Submitted->value));

        return new DashboardMyWorkData(
            assignedOpen: $this->assignedOpenQuery($tenant, $user)->toBase()->count(),
            dueThisWeek: $dueThisWeekQuery->toBase()->count(),
            overdue: $overdueQuery->toBase()->count(),
            awaitingMyReview: $awaitingReviewQuery->toBase()->count(),
            agenda: $this->agenda($tenant, $user, $horizon),
        );
    }

    /**
     * Open dossiers this user is responsible for. A fresh builder each call so
     * the four counters cannot leak predicates into one another.
     *
     * @return Builder<Dossier>
     */
    private function assignedOpenQuery(Tenant $tenant, User $user): Builder
    {
        $query = Dossier::query()
            ->whereBelongsTo($tenant)
            ->where('responsible_user_id', $user->id);

        $query->getQuery()->whereNotIn('status', DossierStatus::lockedValues());

        return $query;
    }

    /**
     * Dated dossiers from today to the horizon, soonest first, plus anything
     * already overdue — the agenda is what to act on, not only what is upcoming.
     *
     * @return list<DashboardAgendaItemData>
     */
    private function agenda(Tenant $tenant, User $user, mixed $horizon): array
    {
        $query = $this->assignedOpenQuery($tenant, $user)->with(['client:id,name']);

        $query->getQuery()
            ->whereNotNull('due_date')
            ->where('due_date', '<=', $horizon)
            // Most urgent first; overdue rows sort above today's.
            ->orderBy('due_date')
            ->limit(self::AGENDA_LIMIT);

        /** @var list<DashboardAgendaItemData> $rows */
        $rows = [];

        foreach ($query->get(['id', 'client_id', 'title', 'status', 'due_date']) as $dossier) {
            $client = $dossier->client;

            throw_unless($client instanceof Client, RuntimeException::class, 'Dossier client is missing.');

            $dueDate = $dossier->due_date;

            if ($dueDate === null) {
                continue;
            }

            $rows[] = new DashboardAgendaItemData(
                id: $dossier->id,
                title: $dossier->title,
                clientName: $client->name,
                status: $dossier->status,
                dueDate: $dueDate->toDateString(),
                // Signed, so the client renders "overdue" without redoing date
                // maths across timezones — see resources/js/hooks/use-formatters.
                daysRemaining: (int) today()->diffInDays($dueDate, false),
            );
        }

        return $rows;
    }
}
