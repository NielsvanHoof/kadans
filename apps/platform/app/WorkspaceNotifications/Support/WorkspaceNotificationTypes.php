<?php

declare(strict_types=1);

namespace App\WorkspaceNotifications\Support;

use App\AudioProcessing\Notifications\AudioTranscriptionCompletedNotification;
use App\ClientPortal\Notifications\Outreach\ClientQuestionnaireCompletedNotification;
use App\ClientPortal\Notifications\Session\ClientPortalNewDeviceNotification;

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
