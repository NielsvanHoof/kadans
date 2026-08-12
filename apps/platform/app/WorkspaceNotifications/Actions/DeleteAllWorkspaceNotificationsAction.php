<?php

declare(strict_types=1);

namespace App\WorkspaceNotifications\Actions;

use App\Accounts\Models\User;
use App\Tenancy\Models\Tenant;
use App\WorkspaceNotifications\Support\WorkspaceNotificationTypes;
use Illuminate\Notifications\DatabaseNotification;

/**
 * Delete every workspace notification for the authenticated user in this tenant.
 */
final class DeleteAllWorkspaceNotificationsAction
{
    public function handle(User $user, Tenant $tenant): int
    {
        $query = DatabaseNotification::query()
            ->where('notifiable_type', $user->getMorphClass())
            ->where('notifiable_id', $user->getKey());

        $query->getQuery()->whereIn('type', WorkspaceNotificationTypes::databaseTypes());

        $query->getQuery()->whereRaw("data->>'tenant_id' = ?", [(string) $tenant->getKey()]);

        return $query->toBase()->delete();
    }
}
