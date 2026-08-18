<?php

declare(strict_types=1);

namespace App\WorkspaceNotifications\Actions;

use App\Accounts\Models\User;
use App\Tenancy\Models\Tenant;
use App\WorkspaceNotifications\Support\WorkspaceNotificationTypes;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Notifications\DatabaseNotification;

/**
 * Delete a single workspace notification for the authenticated user.
 */
final class DeleteWorkspaceNotificationAction
{
    public function handle(User $user, Tenant $tenant, string $notificationId): void
    {
        $query = DatabaseNotification::query()
            ->whereKey($notificationId)
            ->where('notifiable_type', $user->getMorphClass())
            ->where('notifiable_id', $user->getKey());

        $query->getQuery()->whereIn('type', WorkspaceNotificationTypes::databaseTypes());

        $query->getQuery()->whereRaw("data->>'tenant_id' = ?", [(string) $tenant->getKey()]);

        $notification = $query->first();

        if (! $notification instanceof DatabaseNotification) {
            throw (new ModelNotFoundException)->setModel(
                DatabaseNotification::class,
                [$notificationId],
            );
        }

        $notification->delete();
    }
}
