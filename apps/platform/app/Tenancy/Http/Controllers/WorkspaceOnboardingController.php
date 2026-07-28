<?php

declare(strict_types=1);

namespace App\Tenancy\Http\Controllers;

use App\Shared\Http\Controllers\Controller;
use App\Tenancy\Actions\ProvisionTenantAction;
use App\Tenancy\Http\Requests\StoreWorkspaceRequest;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

final class WorkspaceOnboardingController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('onboarding/firm');
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

        $request->session()->put('active_tenant_id', $tenant->id);
        $request->session()->flash('inertia.refresh.workspaces', true);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Firm created. Add your first client to get started.'),
        ]);

        return to_route('workspaces.clients.create', ['tenant' => $tenant]);
    }
}
