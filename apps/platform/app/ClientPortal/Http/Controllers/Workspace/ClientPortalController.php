<?php

declare(strict_types=1);

namespace App\ClientPortal\Http\Controllers;

use App\ClientPortal\Actions\RecordClientPortalAccessAction;
use App\ClientPortal\Http\Middleware\ResolveClientPortalGrant;
use App\ClientPortal\Models\ClientAccessGrant;
use App\ClientPortal\Queries\FetchClientPortalQuery;
use App\ClientPortal\Support\PortalSessionWindow;
use App\Shared\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

use function Illuminate\Support\defer;

final class ClientPortalController extends Controller
{
    /**
     * Show the guest document portal for a valid access grant.
     */
    public function show(
        Request $request,
        FetchClientPortalQuery $getClientPortalData,
        RecordClientPortalAccessAction $recordClientPortalAccess,
    ): Response {
        $grant = $this->grantFromRequest($request);

        // Side effects (grant touch, dossier stamp, audit) live in the Action.
        // Deferred: the rendered page reads none of it, so it runs after the
        // response flushes. The tenant is still current in terminate middleware,
        // which the dossier stamp needs — TenantScope would otherwise match no
        // rows. Covered by the "records portal access after the response" test.
        defer(fn () => $recordClientPortalAccess->handle($grant));

        return Inertia::render('portal/show', $getClientPortalData->handle(
            $grant,
            $this->sessionWindowFromRequest($request),
        ));
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
