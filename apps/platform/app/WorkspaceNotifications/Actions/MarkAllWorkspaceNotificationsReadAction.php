<?php

declare(strict_types=1);

namespace App\WorkspaceNotifications\Actions;

use App\Accounts\Models\User;
use App\ClientPortal\Notifications\ClientQuestionnaireCompletedNotification;
use App\Tenancy\Models\Tenant;
use Illuminate\Notifications\DatabaseNotification;

/**
 * Mark every unread workspace notification as read for the authenticated user.
 */
final class MarkAllWorkspaceNotificationsReadAction
{
    public function handle(User $user, Tenant $tenant): int
    {
        // Query the notifications table directly to keep Larastan happy with Eloquent builders.
        $query = DatabaseNotification::query()
            ->where('notifiable_type', $user->getMorphClass())
            ->where('notifiable_id', $user->getKey())
            ->where('type', ClientQuestionnaireCompletedNotification::DatabaseType);

        $query->getQuery()->whereRaw("data->>'tenant_id' = ?", [(string) $tenant->getKey()]);

        // whereNull/update via base query avoids Larastan staticMethod.dynamicCall.
        $query->getQuery()->whereNull('read_at');

        return $query->toBase()->update(['read_at' => now()]);
    }
}
