<?php

declare(strict_types=1);

namespace App\Tenancy\Http\Controllers\Settings;

use App\Shared\Http\Controllers\Controller;
use App\Shared\Http\Toast;
use App\Tenancy\Actions\Settings\UpdateTenantSettingsAction;
use App\Tenancy\Data\Settings\WorkspaceSettingsPageData;
use App\Tenancy\Http\Requests\Settings\UpdateTenantSettingsRequest;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Queries\Settings\ResolveTenantSettingsQuery;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

final class WorkspaceSettingsController extends Controller
{
    public function index(
        Tenant $tenant,
        ResolveTenantSettingsQuery $resolveTenantSettings,
    ): Response {
        $settings = $resolveTenantSettings->forTenant($tenant);

        $this->authorize('view', $settings);

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
        $updateTenantSettings->handle($tenant, $request->settingsData());

        Toast::success(__('Firm settings saved.'));

        return to_route('workspaces.settings.index', $this->workspaceRouteParameters());
    }
}
