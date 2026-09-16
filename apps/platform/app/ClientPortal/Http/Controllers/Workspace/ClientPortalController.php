<?php

declare(strict_types=1);

namespace App\ClientPortal\Http\Controllers\Workspace;

use App\ClientPortal\Actions\Session\RecordClientPortalAccessAction;
use App\ClientPortal\Data\Workspace\PortalDossierSummaryData;
use App\ClientPortal\Enums\ClientAccessGrantScope;
use App\ClientPortal\Http\Middleware\ResolveClientPortalGrant;
use App\ClientPortal\Models\ClientAccessGrant;
use App\ClientPortal\Queries\Access\ResolveGrantedDossierQuery;
use App\ClientPortal\Queries\Workspace\FetchClientPortalLandingQuery;
use App\ClientPortal\Queries\Workspace\FetchClientPortalQuery;
use App\ClientPortal\Support\Session\PortalSessionWindow;
use App\Dossiers\Models\Dossier;
use App\Shared\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

use function array_values;
use function Illuminate\Support\defer;

final class ClientPortalController extends Controller
{
    public function __construct(
        private readonly ResolveGrantedDossierQuery $resolveGrantedDossier,
        private readonly FetchClientPortalLandingQuery $fetchClientPortalLanding,
    ) {}

    /**
     * Where the link lands.
     *
     * A list is only worth showing when there is a choice to make, so a grant
     * that opens exactly one dossier renders that dossier here rather than
     * bouncing through a redirect. That is not only for the client's sake —
     * it is what keeps every link already sitting in a client's inbox behaving
     * exactly as it did, same URL, same page, same props.
     *
     * Zero reachable dossiers renders the list too, empty. That is a real
     * state, not an error: the link is valid and the client is who they say
     * they are, there is simply nothing waiting. A 404 would send them to the
     * app-wide error page, which offers a guest a "Go to login" button they
     * have no account for.
     */
    public function index(
        Request $request,
        FetchClientPortalQuery $getClientPortalData,
        RecordClientPortalAccessAction $recordClientPortalAccess,
    ): Response {
        $grant = $this->grantFromRequest($request);

        $reachable = $this->fetchClientPortalLanding->reachableDossiersWithItems($grant);

        if ($reachable->count() === 1) {
            return $this->renderDossier(
                $request,
                $grant,
                $reachable->firstOrFail(),
                $getClientPortalData,
                $recordClientPortalAccess,
            );
        }

        defer(fn () => $recordClientPortalAccess->handle($grant));

        return Inertia::render('portal/index', $this->fetchClientPortalLanding->handle(
            $grant,
            $this->sessionWindowFromRequest($request),
        ));
    }

    /**
     * Show one dossier's questionnaire.
     */
    public function show(
        Request $request,
        int $dossier,
        FetchClientPortalQuery $getClientPortalData,
        RecordClientPortalAccessAction $recordClientPortalAccess,
    ): Response {
        $grant = $this->grantFromRequest($request);

        return $this->renderDossier(
            $request,
            $grant,
            $this->resolveGrantedDossier->handle($grant, $dossier),
            $getClientPortalData,
            $recordClientPortalAccess,
        );
    }

    private function renderDossier(
        Request $request,
        ClientAccessGrant $grant,
        Dossier $dossier,
        FetchClientPortalQuery $getClientPortalData,
        RecordClientPortalAccessAction $recordClientPortalAccess,
    ): Response {
        // Side effects (grant touch, dossier stamp, audit) live in the Action.
        // Deferred: the rendered page reads none of it, so it runs after the
        // response flushes. The tenant is still current in terminate middleware,
        // which the dossier stamp needs — TenantScope would otherwise match no
        // rows. Covered by the "records portal access after the response" test.
        defer(fn () => $recordClientPortalAccess->handle($grant, $dossier));

        return Inertia::render('portal/show', $getClientPortalData->handle(
            $grant,
            $dossier,
            $this->sessionWindowFromRequest($request),
            $this->otherDossiers($grant, $dossier),
        ));
    }

    /**
     * The rest of what this link opens, for the switcher above the form.
     *
     * Empty for a dossier-scoped grant, which is what keeps its payload — and
     * therefore its page — identical to before.
     *
     * @return list<PortalDossierSummaryData>
     */
    private function otherDossiers(ClientAccessGrant $grant, Dossier $current): array
    {
        if ($grant->scope === ClientAccessGrantScope::Dossier) {
            return [];
        }

        $summaries = $this->fetchClientPortalLanding
            ->reachableDossiersWithItems($grant)
            ->reject(fn (Dossier $dossier): bool => $dossier->is($current))
            ->map(fn (Dossier $dossier): PortalDossierSummaryData => $this->fetchClientPortalLanding->summarise($dossier))
            ->all();

        return array_values($summaries);
    }

    private function grantFromRequest(Request $request): ClientAccessGrant
    {
        $grant = $request->attributes->get(ResolveClientPortalGrant::REQUEST_ATTRIBUTE);

        abort_unless($grant instanceof ClientAccessGrant, 404);

        return $grant;
    }

    /**
     * Set by ResolveClientPortalGrant after it slides the idle deadline, so the
     * page always renders the deadline the client actually has.
     */
    private function sessionWindowFromRequest(Request $request): PortalSessionWindow
    {
        $window = $request->attributes->get(ResolveClientPortalGrant::REQUEST_SESSION_WINDOW);

        abort_unless($window instanceof PortalSessionWindow, 404);

        return $window;
    }
}
