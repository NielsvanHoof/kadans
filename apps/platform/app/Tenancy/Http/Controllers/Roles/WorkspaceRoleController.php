<?php

declare(strict_types=1);

namespace App\Tenancy\Http\Controllers\Roles;

use App\Accounts\Http\Concerns\ResolvesCurrentUser;
use App\Shared\Http\Controllers\Controller;
use App\Shared\Http\Toast;
use App\Tenancy\Actions\Roles\CreateTenantRoleAction;
use App\Tenancy\Actions\Roles\DeleteTenantRoleAction;
use App\Tenancy\Actions\Roles\UpdateTenantRoleAction;
use App\Tenancy\Http\Requests\Roles\StoreTenantRoleRequest;
use App\Tenancy\Http\Requests\Roles\UpdateTenantRoleRequest;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Models\TenantRole;
use App\Tenancy\Queries\Roles\FetchWorkspaceRolesQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

use function is_array;
use function is_string;

final class WorkspaceRoleController extends Controller
{
    use ResolvesCurrentUser;

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
        $user = $this->currentUser();

        $createTenantRole->handle(
            $tenant,
            $user,
            $request->name(),
            $request->permissions(),
        );

        Toast::success(__('Role created.'));

        return to_route('workspaces.roles.manage', $this->workspaceRouteParameters());
    }

    public function update(
        Tenant $tenant,
        TenantRole $role,
        UpdateTenantRoleRequest $request,
        UpdateTenantRoleAction $updateTenantRole,
    ): RedirectResponse {
        $user = $this->currentUser();

        $this->ensureRoleBelongsToTenant($role, $tenant);

        $updateTenantRole->handle(
            $role,
            $user,
            $request->name(),
            $request->permissions(),
        );

        Toast::success(__('Role updated.'));

        return to_route('workspaces.roles.manage', $this->workspaceRouteParameters());
    }

    public function destroy(
        Tenant $tenant,
        TenantRole $role,
        DeleteTenantRoleAction $deleteTenantRole,
    ): RedirectResponse {
        $user = $this->currentUser();

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

            Toast::error($firstError ?? __('Something went wrong.'));

            return to_route('workspaces.roles.manage', $this->workspaceRouteParameters())
                ->withErrors($exception->errors());
        }

        Toast::success(__('Role deleted.'));

        return to_route('workspaces.roles.manage', $this->workspaceRouteParameters());
    }

    private function ensureRoleBelongsToTenant(TenantRole $role, Tenant $tenant): void
    {
        abort_if($role->tenant_id !== $tenant->id, 404);
    }
}
