<?php

declare(strict_types=1);

namespace App\Tenancy\Http\Controllers;

use App\Accounts\Http\Concerns\ResolvesCurrentUser;
use App\Shared\Http\Controllers\Controller;
use App\Shared\Http\Toast;
use App\Tenancy\Actions\ResendTenantInvitationAction;
use App\Tenancy\Actions\RevokeTenantInvitationAction;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Models\TenantInvitation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class TenantInvitationController extends Controller
{
    use ResolvesCurrentUser;

    public function resend(
        Tenant $tenant,
        TenantInvitation $invitation,
        ResendTenantInvitationAction $resendTenantInvitation,
    ): RedirectResponse {
        $this->ensureInvitationBelongsToTenant($invitation, $tenant);
        $this->authorize('resend', $invitation);

        $resendTenantInvitation->handle($invitation);

        Toast::success(__('Invitation resent.'));

        return to_route('workspaces.members.index', $this->workspaceRouteParameters());
    }

    public function destroy(
        Tenant $tenant,
        TenantInvitation $invitation,
        Request $request,
        RevokeTenantInvitationAction $revokeTenantInvitation,
    ): RedirectResponse {
        $this->ensureInvitationBelongsToTenant($invitation, $tenant);
        $this->authorize('revoke', $invitation);

        $user = $this->currentUser();

        $revokeTenantInvitation->handle($invitation, $user);

        Toast::success(__('Invitation revoked.'));

        return to_route('workspaces.members.index', $this->workspaceRouteParameters());
    }

    private function ensureInvitationBelongsToTenant(TenantInvitation $invitation, Tenant $tenant): void
    {
        abort_if($invitation->tenant_id !== $tenant->id, 404);
    }
}
