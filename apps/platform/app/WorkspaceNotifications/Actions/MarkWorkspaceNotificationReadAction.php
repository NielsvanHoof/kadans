<?php

declare(strict_types=1);

namespace App\WorkspaceNotifications\Actions;

use App\Accounts\Models\User;
use App\AudioProcessing\Notifications\AudioTranscriptionCompletedNotification;
use App\ClientPortal\Notifications\ClientQuestionnaireCompletedNotification;
use App\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Notifications\DatabaseNotification;

/**
 * Mark a single workspace notification as read for the authenticated user.
 */
final class MarkWorkspaceNotificationReadAction
{
    public function handle(User $user, Tenant $tenant, string $notificationId): DatabaseNotification
    {
        $query = DatabaseNotification::query()
            ->whereKey($notificationId)
            ->where('notifiable_type', $user->getMorphClass())
            ->where('notifiable_id', $user->getKey());

        $query->getQuery()->whereIn('type', [
            ClientQuestionnaireCompletedNotification::DatabaseType,
            AudioTranscriptionCompletedNotification::DatabaseType,
        ]);

        $query->getQuery()->whereRaw("data->>'tenant_id' = ?", [(string) $tenant->getKey()]);

        $notification = $query->first();

        if (! $notification instanceof DatabaseNotification) {
            throw (new ModelNotFoundException)->setModel(
                DatabaseNotification::class,
                [$notificationId],
            );
        }

        $notification->markAsRead();

        return $notification;
    }
}
