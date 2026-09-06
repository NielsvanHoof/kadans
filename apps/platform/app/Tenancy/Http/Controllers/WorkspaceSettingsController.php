<?php

declare(strict_types=1);

namespace App\Tenancy\Http\Controllers;

use App\Shared\Http\Controllers\Controller;
use App\Shared\Http\Toast;
use App\Tenancy\Actions\RenameTenantAction;
use App\Tenancy\Actions\UpdateTenantSettingsAction;
use App\Tenancy\Data\WorkspaceSettingsPageData;
use App\Tenancy\Http\Requests\UpdateTenantSettingsRequest;
use App\Tenancy\Http\Requests\UpdateWorkspaceRequest;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Queries\ResolveTenantSettingsQuery;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

final class WorkspaceSettingsController extends Controller
{
    public function index(
        Tenant $tenant,
        ResolveTenantSettingsQuery $resolveTenantSettings,
    ): Response {
        $this->authorize('view', $resolveTenantSettings->forTenant($tenant));

        return Inertia::render(
            'workspaces/settings/index',
            new WorkspaceSettingsPageData(
                settings: $resolveTenantSettings->handle($tenant),
            ),
        );
    }

    public function update(
        Tenant $tenant,
        UpdateTenantSettingsRequest $request,
        UpdateTenantSettingsAction $updateTenantSettings,
    ): RedirectResponse {
        $updateTenantSettings->handle($tenant, $request->settingsAttributes());

        Toast::success(__('Firm settings saved.'));

        return to_route('workspaces.settings.index', $this->workspaceRouteParameters());
    }

    public function updateFirm(
        Tenant $tenant,
        UpdateWorkspaceRequest $request,
        RenameTenantAction $renameTenant,
    ): RedirectResponse {
        $renameTenant->handle(
            $tenant,
            (string) $request->validated('name'),
            (string) $request->validated('slug'),
        );

        // The switcher list is a once prop; without this it would keep showing
        // the old name until the next full page load.
        $request->session()->flash('inertia.refresh.workspaces', true);

        Toast::success(__('Firm details saved.'));

        // workspaceRouteParameters() reads the route-bound tenant, which the
        // action just renamed — so this resolves to the new address.
        return to_route('workspaces.settings.index', $this->workspaceRouteParameters());
    }
}
