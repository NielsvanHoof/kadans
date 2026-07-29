<?php

declare(strict_types=1);

namespace App\Tenancy\Http\Controllers;

use App\Accounts\Models\User;
use App\Shared\Http\Controllers\Controller;
use App\Tenancy\Models\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ActiveTenantController extends Controller
{
    public function store(Request $request, Tenant $tenant): RedirectResponse
    {
        $user = $request->user();

        abort_if(! $user instanceof User || ! $user->canAccessTenant($tenant), Response::HTTP_FORBIDDEN);

        $request->session()->put('active_tenant_id', $tenant->id);

        return to_route('workspaces.dashboard', ['tenant' => $tenant]);
    }
}
