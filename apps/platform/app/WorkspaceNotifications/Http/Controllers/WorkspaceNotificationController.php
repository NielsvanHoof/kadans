<?php

declare(strict_types=1);

namespace App\WorkspaceNotifications\Http\Controllers;

use App\Accounts\Http\Concerns\ResolvesCurrentUser;
use App\Shared\Http\Controllers\Controller;
use App\Tenancy\Models\Tenant;
use App\WorkspaceNotifications\Actions\DeleteAllWorkspaceNotificationsAction;
use App\WorkspaceNotifications\Actions\DeleteWorkspaceNotificationAction;
use App\WorkspaceNotifications\Actions\MarkAllWorkspaceNotificationsReadAction;
use App\WorkspaceNotifications\Actions\MarkWorkspaceNotificationReadAction;
use Illuminate\Http\RedirectResponse;

final class WorkspaceNotificationController extends Controller
{
    use ResolvesCurrentUser;

    /**
     * Mark one workspace notification as read.
     */
    public function update(
        Tenant $tenant,
        string $notification,
        MarkWorkspaceNotificationReadAction $markWorkspaceNotificationRead,
    ): RedirectResponse {
        $user = $this->currentUser();

        $markWorkspaceNotificationRead->handle($user, $tenant, $notification);

        return back();
    }

    /**
     * Mark every unread workspace notification as read for this tenant.
     */
    public function store(
        Tenant $tenant,
        MarkAllWorkspaceNotificationsReadAction $markAllWorkspaceNotificationsRead,
    ): RedirectResponse {
        $user = $this->currentUser();

        $markAllWorkspaceNotificationsRead->handle($user, $tenant);

        return back();
    }

    /**
     * Delete one workspace notification.
     */
    public function destroy(
        Tenant $tenant,
        string $notification,
        DeleteWorkspaceNotificationAction $deleteWorkspaceNotification,
    ): RedirectResponse {
        $user = $this->currentUser();

        $deleteWorkspaceNotification->handle($user, $tenant, $notification);

        return back();
    }

    /**
     * Delete every workspace notification for this tenant.
     */
    public function destroyAll(
        Tenant $tenant,
        DeleteAllWorkspaceNotificationsAction $deleteAllWorkspaceNotifications,
    ): RedirectResponse {
        $user = $this->currentUser();

        $deleteAllWorkspaceNotifications->handle($user, $tenant);

        return back();
    }
}
