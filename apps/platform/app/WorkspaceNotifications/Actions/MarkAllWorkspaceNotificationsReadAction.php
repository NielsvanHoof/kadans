<?php

declare(strict_types=1);

namespace App\WorkspaceNotifications\Actions;

use App\Accounts\Models\User;
use App\Tenancy\Models\Tenant;
use App\WorkspaceNotifications\Support\WorkspaceNotificationTypes;
use Illuminate\Notifications\DatabaseNotification;

/**
 * Mark every unread workspace notification as read for the authenticated user.
 */
final class MarkAllWorkspaceNotificationsReadAction
{
    public function handle(User $user, Tenant $tenant): int
    {
        $query = DatabaseNotification::query()
            ->where('notifiable_type', $user->getMorphClass())
            ->where('notifiable_id', $user->getKey());

        $query->getQuery()->whereIn('type', WorkspaceNotificationTypes::databaseTypes());

        $query->getQuery()->whereRaw("data->>'tenant_id' = ?", [(string) $tenant->getKey()]);
        $query->getQuery()->whereNull('read_at');

        return $query->toBase()->update(['read_at' => now()]);
    }
}
