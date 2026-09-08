<?php

declare(strict_types=1);

namespace App\Reporting\Http\Controllers;

use App\Accounts\Http\Concerns\ResolvesCurrentUser;
use App\Dossiers\Models\Dossier;
use App\Reporting\Queries\FetchWorkspaceDashboardQuery;
use App\Shared\Http\Controllers\Controller;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Models\TenantMembership;
use App\Tenancy\Queries\FetchActiveTenantMembershipsForUserQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

final class DashboardController extends Controller
{
    use ResolvesCurrentUser;

    public function index(
        FetchActiveTenantMembershipsForUserQuery $getActiveTenantMembershipsForUser,
    ): RedirectResponse|Response {
        $user = $this->currentUser();

        $memberships = $getActiveTenantMembershipsForUser->handle($user);

        if ($memberships->isEmpty()) {
            return to_route('onboarding.firm.create');
        }

        $tenant = Tenant::current();

        if ($tenant instanceof Tenant) {
            return to_route('workspaces.dashboard', ['tenant' => $tenant]);
        }

        return Inertia::render('dashboard', [
            'firms' => $memberships
                ->map(function (TenantMembership $membership): array {
                    $tenant = $this->resolveMembershipTenant($membership);

                    return [
                        'name' => $tenant->name,
                        'slug' => $tenant->slug,
                    ];
                })
                ->all(),
        ]);
    }

    public function show(
        Tenant $tenant,
        Request $request,
        FetchWorkspaceDashboardQuery $getWorkspaceDashboardData,
    ): Response {
        $this->authorize('viewAny', Dossier::class);

        return Inertia::render(
            'workspaces/dashboard',
            $getWorkspaceDashboardData->handle($request, $tenant),
        );
    }

    private function resolveMembershipTenant(TenantMembership $membership): Tenant
    {
        $tenant = $membership->tenant;

        throw_unless($tenant instanceof Tenant, RuntimeException::class, 'Tenant membership is missing its tenant.');

        return $tenant;
    }
}
