<?php

declare(strict_types=1);

namespace App\Http\Controllers\Platform;

use App\Actions\Platform\EndSupportSessionAction;
use App\Actions\Platform\StartSupportSessionAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\StartSupportSessionRequest;
use App\Models\Tenant;
use App\Queries\Platform\FetchPlatformSupportTenantsQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Internal firm picker for platform support staff.
 */
final class SupportSessionController extends Controller
{
    public function index(FetchPlatformSupportTenantsQuery $fetchPlatformSupportTenants): Response
    {
        $filters = request()->input('filter', []);

        return Inertia::render('platform/support', [
            'tenants' => $fetchPlatformSupportTenants->handle(),
            'filters' => is_array($filters) ? $filters : [],
        ]);
    }

    public function store(
        StartSupportSessionRequest $request,
        Tenant $tenant,
        StartSupportSessionAction $startSupportSession,
    ): RedirectResponse {
        $user = $request->user();

        if ($user === null) {
            abort(403);
        }

        $startSupportSession->handle(
            user: $user,
            tenant: $tenant,
            reason: $request->reason(),
        );

        return to_route('workspaces.dashboard', ['tenant' => $tenant]);
    }

    public function destroy(
        Request $request,
        EndSupportSessionAction $endSupportSession,
    ): RedirectResponse {
        $endSupportSession->handle(user: $request->user());

        return to_route('platform.support.index');
    }
}
