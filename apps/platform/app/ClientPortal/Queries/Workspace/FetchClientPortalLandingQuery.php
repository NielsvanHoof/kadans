<?php

declare(strict_types=1);

namespace App\ClientPortal\Queries\Workspace;

use App\Accounts\Models\User;
use App\ClientPortal\Data\Session\PortalSessionData;
use App\ClientPortal\Data\Workspace\PortalClientData;
use App\ClientPortal\Data\Workspace\PortalDossierSummaryData;
use App\ClientPortal\Data\Workspace\PortalFirmData;
use App\ClientPortal\Data\Workspace\PortalLandingPageData;
use App\ClientPortal\Models\ClientAccessGrant;
use App\ClientPortal\Queries\Access\FetchReachableDossiersQuery;
use App\ClientPortal\Support\Session\PortalSessionWindow;
use App\Dossiers\Actions\DocumentRequests\ResolveApplicableDocumentRequestsAction;
use App\Dossiers\Models\DocumentRequest;
use App\Dossiers\Models\Dossier;
use App\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Config;

/**
 * What one client still owes their firm, across every dossier they can open.
 *
 * Counting has to happen in PHP. Whether a question applies depends on the
 * answers to its siblings (ResolveApplicableDocumentRequestsAction walks the
 * visibility rules), which is not something SQL can be asked. That is bounded
 * here rather than unbounded: the set is one client's shared dossiers, and the
 * document requests for all of them are eager-loaded in a single query, so this
 * is N passes over memory, not N round trips.
 */
final readonly class FetchClientPortalLandingQuery
{
    public function __construct(
        private FetchReachableDossiersQuery $fetchReachableDossiers,
        private ResolveApplicableDocumentRequestsAction $resolveApplicableDocumentRequests,
    ) {}

    public function handle(
        ClientAccessGrant $grant,
        PortalSessionWindow $sessionWindow,
    ): PortalLandingPageData {
        $dossiers = $this->reachableDossiersWithItems($grant);

        /** @var list<PortalDossierSummaryData> $waiting */
        $waiting = [];
        /** @var list<PortalDossierSummaryData> $completed */
        $completed = [];

        foreach ($dossiers as $dossier) {
            $summary = $this->summarise($dossier);

            if ($summary->remaining > 0) {
                $waiting[] = $summary;

                continue;
            }

            $completed[] = $summary;
        }

        $grant->loadMissing(['client:id,name,email', 'createdBy:id,name,email', 'tenant:id,name']);

        return new PortalLandingPageData(
            firm: $this->firm($grant),
            client: new PortalClientData(name: $grant->client->name),
            session: PortalSessionData::fromWindow($sessionWindow),
            waiting: $waiting,
            completed: $completed,
        );
    }

    /**
     * @return Collection<int, Dossier>
     */
    public function reachableDossiersWithItems(ClientAccessGrant $grant): Collection
    {
        return $this->fetchReachableDossiers->handle($grant, withDocumentRequests: true);
    }

    public function summarise(Dossier $dossier): PortalDossierSummaryData
    {
        $applicable = $this->resolveApplicableDocumentRequests->handle($dossier->documentRequests);

        // A locked dossier owes nothing, whatever its items say. Staff can
        // complete a dossier with questions still open, and telling the client
        // "1 still needed" for something DocumentRequestTransitions::canSubmit()
        // will refuse is an instruction they cannot follow.
        $remaining = $dossier->status->isLocked()
            ? $applicable->take(0)
            : $applicable->filter(
                fn (DocumentRequest $documentRequest): bool => $documentRequest->status->isAwaitingClient(),
            );

        return new PortalDossierSummaryData(
            id: $dossier->id,
            title: $dossier->title,
            reference: $dossier->reference,
            status: $dossier->status,
            dueDate: $dossier->due_date?->toDateString(),
            remaining: $remaining->count(),
            total: $applicable->count(),
        );
    }

    /**
     * Same fallback the single-dossier page uses: whoever issued the link always
     * exists, because `created_by` cascades on delete.
     */
    private function firm(ClientAccessGrant $grant): PortalFirmData
    {
        $tenant = $grant->tenant;

        if (! $tenant instanceof Tenant) {
            throw (new ModelNotFoundException)->setModel(Tenant::class, [$grant->tenant_id]);
        }

        $contact = $grant->createdBy;

        if (! $contact instanceof User) {
            throw (new ModelNotFoundException)->setModel(User::class, [$grant->created_by]);
        }

        return new PortalFirmData(
            name: $tenant->name === '' ? Config::string('app.name') : $tenant->name,
            contactName: $contact->name,
            contactEmail: $contact->email,
        );
    }
}
