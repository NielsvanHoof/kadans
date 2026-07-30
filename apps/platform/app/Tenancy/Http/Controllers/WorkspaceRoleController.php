<?php

declare(strict_types=1);

namespace App\Tenancy\Http\Controllers;

use App\Accounts\Models\User;
use App\Shared\Http\Controllers\Controller;
use App\Tenancy\Actions\CreateTenantRoleAction;
use App\Tenancy\Actions\DeleteTenantRoleAction;
use App\Tenancy\Actions\UpdateTenantRoleAction;
use App\Tenancy\Http\Requests\StoreTenantRoleRequest;
use App\Tenancy\Http\Requests\UpdateTenantRoleRequest;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Models\TenantRole;
use App\Tenancy\Queries\FetchWorkspaceRolesQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

use function is_array;
use function is_string;

final class WorkspaceRoleController extends Controller
{
    public function index(
        Tenant $tenant,
        FetchWorkspaceRolesQuery $fetchWorkspaceRoles,
    ): Response {
        $this->authorize('viewAny', TenantRole::class);

        return Inertia::render(
            'workspaces/roles/index',
            $fetchWorkspaceRoles->handle(
                $tenant,
                request()->user()?->can('create', TenantRole::class) ?? false,
            ),
        );
    }

    public function create(
        Tenant $tenant,
        FetchWorkspaceRolesQuery $fetchWorkspaceRoles,
    ): Response {
        $this->authorize('create', TenantRole::class);

        return Inertia::render(
            'workspaces/roles/manage',
            $fetchWorkspaceRoles->handle($tenant, true),
        );
    }

    public function store(
        Tenant $tenant,
        StoreTenantRoleRequest $request,
        CreateTenantRoleAction $createTenantRole,
    ): RedirectResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $createTenantRole->handle(
            $tenant,
            $user,
            $request->name(),
            $request->permissions(),
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Role created.'),
        ]);

        return to_route('workspaces.roles.manage', $this->workspaceRouteParameters());
    }

    public function update(
        Tenant $tenant,
        TenantRole $role,
        UpdateTenantRoleRequest $request,
        UpdateTenantRoleAction $updateTenantRole,
    ): RedirectResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $this->ensureRoleBelongsToTenant($role, $tenant);

        $updateTenantRole->handle(
            $role,
            $user,
            $request->name(),
            $request->permissions(),
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Role updated.'),
        ]);

        return to_route('workspaces.roles.manage', $this->workspaceRouteParameters());
    }

    public function destroy(
        Tenant $tenant,
        TenantRole $role,
        Request $request,
        DeleteTenantRoleAction $deleteTenantRole,
    ): RedirectResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $this->ensureRoleBelongsToTenant($role, $tenant);
        $this->authorize('delete', $role);

        try {
            $deleteTenantRole->handle($tenant, $role, $user);
        } catch (ValidationException $exception) {
            $errors = $exception->errors();
            $firstError = null;

            foreach ($errors as $messages) {
                if (! is_array($messages)) {
                    continue;
                }
                if ($messages === []) {
                    continue;
                }
                $candidate = $messages[0] ?? null;

                if (is_string($candidate) && $candidate !== '') {
                    $firstError = $candidate;
                    break;
                }
            }

            Inertia::flash('toast', [
                'type' => 'error',
                'message' => $firstError ?? __('Something went wrong.'),
            ]);

            return to_route('workspaces.roles.manage', $this->workspaceRouteParameters())
                ->withErrors($exception->errors());
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Role deleted.'),
        ]);

        return to_route('workspaces.roles.manage', $this->workspaceRouteParameters());
    }

    private function ensureRoleBelongsToTenant(TenantRole $role, Tenant $tenant): void
    {
        abort_if($role->tenant_id !== $tenant->id, 404);
    }
}
