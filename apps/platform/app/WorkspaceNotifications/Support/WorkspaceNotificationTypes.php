<?php

declare(strict_types=1);

namespace App\WorkspaceNotifications\Support;

use App\AudioProcessing\Notifications\AudioTranscriptionCompletedNotification;
use App\ClientPortal\Notifications\ClientPortalNewDeviceNotification;
use App\ClientPortal\Notifications\ClientQuestionnaireCompletedNotification;

/**
 * Database notification type keys shown in the workspace bell inbox.
 */
final class WorkspaceNotificationTypes
{
    /**
     * @return list<string>
     */
    public static function databaseTypes(): array
    {
        return [
            ClientQuestionnaireCompletedNotification::DatabaseType,
            AudioTranscriptionCompletedNotification::DatabaseType,
            ClientPortalNewDeviceNotification::DatabaseType,
        ];
    }
}
