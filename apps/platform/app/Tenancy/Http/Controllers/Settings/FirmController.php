<?php

declare(strict_types=1);

namespace App\Tenancy\Http\Controllers;

use App\Shared\Http\Controllers\Controller;
use App\Shared\Http\Toast;
use App\Tenancy\Actions\ProvisionTenantAction;
use App\Tenancy\Http\Requests\StoreWorkspaceRequest;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Lets an existing user create an additional firm (workspace).
 * First-run firm creation lives in WorkspaceOnboardingController.
 */
final class FirmController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('firms/create');
    }

    public function store(
        StoreWorkspaceRequest $request,
        ProvisionTenantAction $provisionTenant,
    ): RedirectResponse {
        $name = $request->validated('name');
        $tenant = $provisionTenant->handle(
            $name,
            $request->authenticatedUser(),
        );

        // Switch the session to the new firm and refresh the
        // once-cached workspaces list in the sidebar switcher.
        $request->session()->put('active_tenant_id', $tenant->id);
        $request->session()->flash('inertia.refresh.workspaces', true);

        Toast::success(__('Firm created. Add your first client to get started.'));

        return to_route('workspaces.clients.create', ['tenant' => $tenant]);
    }
}
