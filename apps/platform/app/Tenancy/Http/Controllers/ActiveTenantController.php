<?php

declare(strict_types=1);

namespace App\Tenancy\Http\Controllers;

use App\Accounts\Http\Concerns\ResolvesCurrentUser;
use App\Shared\Http\Controllers\Controller;
use App\Tenancy\Models\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ActiveTenantController extends Controller
{
    use ResolvesCurrentUser;

    public function store(Request $request, Tenant $tenant): RedirectResponse
    {
        abort_unless(
            $this->currentUser()->canAccessTenant($tenant),
            Response::HTTP_FORBIDDEN,
        );

        $request->session()->put('active_tenant_id', $tenant->id);

        return to_route('workspaces.dashboard', ['tenant' => $tenant]);
    }
}
