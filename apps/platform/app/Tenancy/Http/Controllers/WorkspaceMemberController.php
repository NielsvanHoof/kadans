<?php

declare(strict_types=1);

namespace App\Tenancy\Http\Controllers;

use App\Accounts\Http\Concerns\ResolvesCurrentUser;
use App\Shared\Http\Controllers\Controller;
use App\Shared\Http\Toast;
use App\Tenancy\Actions\InviteTenantMemberAction;
use App\Tenancy\Actions\RemoveTenantMemberAction;
use App\Tenancy\Actions\SuspendTenantMemberAction;
use App\Tenancy\Actions\UpdateTenantMemberRoleAction;
use App\Tenancy\Enums\TenantMembershipStatus;
use App\Tenancy\Http\Requests\InviteTenantMemberRequest;
use App\Tenancy\Http\Requests\UpdateTenantMemberRequest;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Models\TenantMembership;
use App\Tenancy\Queries\FetchWorkspaceMembersQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

use function is_string;

final class WorkspaceMemberController extends Controller
{
    use ResolvesCurrentUser;

    public function index(
        Tenant $tenant,
        Request $request,
        FetchWorkspaceMembersQuery $fetchWorkspaceMembers,
    ): Response {
        $this->authorize('viewAny', TenantMembership::class);

        $user = $this->currentUser();

        return Inertia::render(
            'workspaces/members/index',
            $fetchWorkspaceMembers->handle($tenant, $user),
        );
    }

    public function store(
        Tenant $tenant,
        InviteTenantMemberRequest $request,
        InviteTenantMemberAction $inviteTenantMember,
    ): RedirectResponse {
        $user = $this->currentUser();

        $inviteTenantMember->handle(
            $tenant,
            $user,
            $request->email(),
            $request->role(),
        );

        Toast::success(__('Invitation sent.'));

        return to_route('workspaces.members.index', $this->workspaceRouteParameters());
    }

    public function update(
        Tenant $tenant,
        TenantMembership $membership,
        UpdateTenantMemberRequest $request,
        UpdateTenantMemberRoleAction $updateTenantMemberRole,
        SuspendTenantMemberAction $suspendTenantMember,
    ): RedirectResponse {
        $this->ensureMembershipBelongsToTenant($membership, $tenant);

        $user = $this->currentUser();

        $role = $request->role();
        $status = $request->status();

        if (is_string($role)) {
            $updateTenantMemberRole->handle($membership, $user, $role);
        }

        if ($status === TenantMembershipStatus::Suspended) {
            $this->authorize('suspend', $membership);
            $suspendTenantMember->handle($membership, $user);
        }

        Toast::success(__('Member updated.'));

        return to_route('workspaces.members.index', $this->workspaceRouteParameters());
    }

    public function destroy(
        Tenant $tenant,
        TenantMembership $membership,
        Request $request,
        RemoveTenantMemberAction $removeTenantMember,
    ): RedirectResponse {
        $this->ensureMembershipBelongsToTenant($membership, $tenant);
        $this->authorize('remove', $membership);

        $user = $this->currentUser();

        $removeTenantMember->handle($membership, $user);

        Toast::success(__('Member removed.'));

        return to_route('workspaces.members.index', $this->workspaceRouteParameters());
    }

    private function ensureMembershipBelongsToTenant(TenantMembership $membership, Tenant $tenant): void
    {
        abort_if($membership->tenant_id !== $tenant->id, 404);
    }
}
