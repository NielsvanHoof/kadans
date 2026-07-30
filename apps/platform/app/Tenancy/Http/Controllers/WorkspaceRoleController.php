<?php

declare(strict_types=1);

namespace App\Tenancy\Http\Controllers;

use App\Shared\Http\Controllers\Controller;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Models\TenantRole;
use App\Tenancy\Queries\FetchWorkspaceRolesQuery;
use Inertia\Inertia;
use Inertia\Response;

final class WorkspaceRoleController extends Controller
{
    public function index(
        Tenant $tenant,
        FetchWorkspaceRolesQuery $fetchWorkspaceRoles,
    ): Response {
        $this->authorize('viewAny', TenantRole::class);

        return Inertia::render(
            'workspaces/roles/index',
            $fetchWorkspaceRoles->handle($tenant),
        );
    }
}
