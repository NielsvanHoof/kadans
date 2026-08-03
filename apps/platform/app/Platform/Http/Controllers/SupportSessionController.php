<?php

declare(strict_types=1);

namespace App\Platform\Http\Controllers;

use App\Accounts\Http\Concerns\ResolvesCurrentUser;
use App\Platform\Actions\EndSupportSessionAction;
use App\Platform\Actions\StartSupportSessionAction;
use App\Platform\Http\Requests\StartSupportSessionRequest;
use App\Platform\Queries\FetchPlatformSupportTenantsQuery;
use App\Shared\Http\Controllers\Controller;
use App\Tenancy\Models\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

use function is_array;

/**
 * Internal firm picker for platform support staff.
 */
final class SupportSessionController extends Controller
{
    use ResolvesCurrentUser;

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
        $user = $this->currentUser();

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
