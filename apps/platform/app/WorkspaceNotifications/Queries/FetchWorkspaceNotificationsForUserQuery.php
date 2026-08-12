<?php

declare(strict_types=1);

namespace App\WorkspaceNotifications\Queries;

use App\Accounts\Models\User;
use App\AudioProcessing\Notifications\AudioTranscriptionCompletedNotification;
use App\ClientPortal\Notifications\ClientPortalNewDeviceNotification;
use App\ClientPortal\Notifications\ClientQuestionnaireCompletedNotification;
use App\Tenancy\Models\Tenant;
use App\WorkspaceNotifications\Data\WorkspaceNotificationItemData;
use App\WorkspaceNotifications\Data\WorkspaceNotificationsData;
use App\WorkspaceNotifications\Support\WorkspaceNotificationTypes;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;

/**
 * Recent workspace inbox notifications for the staff bell dropdown.
 */
final class FetchWorkspaceNotificationsForUserQuery
{
    private const int Limit = 20;

    public function handle(User $user, Tenant $tenant): WorkspaceNotificationsData
    {
        $baseQuery = DatabaseNotification::query()
            ->where('notifiable_type', $user->getMorphClass())
            ->where('notifiable_id', $user->getKey());

        $baseQuery->getQuery()->whereIn('type', WorkspaceNotificationTypes::databaseTypes());

        $baseQuery->getQuery()->whereRaw("data->>'tenant_id' = ?", [(string) $tenant->getKey()]);

        $unreadQuery = clone $baseQuery;
        $unreadQuery->getQuery()->whereNull('read_at');
        $unreadCount = $unreadQuery->toBase()->count();

        $recentQuery = clone $baseQuery;
        $recentQuery->latest();
        $recentQuery->getQuery()->limit(self::Limit);

        /** @var Collection<int, DatabaseNotification> $notifications */
        $notifications = $recentQuery->get();

        /** @var list<WorkspaceNotificationItemData> $items */
        $items = [];

        foreach ($notifications as $notification) {
            $items[] = $this->mapNotification($notification);
        }

        return new WorkspaceNotificationsData(
            unreadCount: $unreadCount,
            items: $items,
        );
    }

    private function mapNotification(DatabaseNotification $notification): WorkspaceNotificationItemData
    {
        /** @var array<string, mixed> $data */
        $data = $notification->data;

        $type = (string) ($data['type'] ?? ClientQuestionnaireCompletedNotification::DatabaseType);

        [$message, $url, $extra] = match ($type) {
            AudioTranscriptionCompletedNotification::DatabaseType => $this->mapAudioTranscriptionCompleted($data),
            ClientPortalNewDeviceNotification::DatabaseType => $this->mapClientPortalNewDevice($data),
            default => $this->mapClientQuestionnaireCompleted($data),
        };

        return new WorkspaceNotificationItemData(...[
            'id' => $notification->id,
            'type' => $type,
            'message' => $message,
            'url' => $url,
            'readAt' => $notification->read_at?->toDateTimeString(),
            'createdAt' => $notification->created_at?->toDateTimeString() ?? '',
            ...$extra,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: string, 1: string, 2: array<string, mixed>}
     */
    private function mapClientQuestionnaireCompleted(array $data): array
    {
        $clientName = (string) ($data['client_name'] ?? '');
        $dossierTitle = (string) ($data['dossier_title'] ?? '');

        // Rebuilt at read time (rather than trusting the stored message) so the
        // bell always renders in the viewing user's current locale, not
        // whichever locale happened to be active when the queue worker ran.
        $message = __(':client finished the questionnaire for “:dossier”.', [
            'client' => $clientName,
            'dossier' => $dossierTitle,
        ]);

        return [
            $message,
            (string) ($data['dossier_url'] ?? ''),
            [
                'dossierId' => (int) ($data['dossier_id'] ?? 0),
                'dossierTitle' => $dossierTitle,
                'clientName' => $clientName,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: string, 1: string, 2: array<string, mixed>}
     */
    private function mapClientPortalNewDevice(array $data): array
    {
        $clientName = (string) ($data['client_name'] ?? '');
        $dossierTitle = (string) ($data['dossier_title'] ?? '');

        $message = __('The portal link for “:dossier” was opened from a new device.', [
            'dossier' => $dossierTitle,
        ]);

        return [
            $message,
            (string) ($data['dossier_url'] ?? ''),
            [
                'dossierId' => isset($data['dossier_id']) ? (int) $data['dossier_id'] : null,
                'dossierTitle' => $dossierTitle,
                'clientName' => $clientName,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: string, 1: string, 2: array<string, mixed>}
     */
    private function mapAudioTranscriptionCompleted(array $data): array
    {
        $originalFilename = (string) ($data['original_filename'] ?? '');

        $message = __('Transcript ready for :file.', [
            'file' => $originalFilename,
        ]);

        return [
            $message,
            (string) ($data['audio_recording_url'] ?? ''),
            [
                'dossierId' => isset($data['dossier_id']) ? (int) $data['dossier_id'] : null,
                'dossierTitle' => isset($data['dossier_title']) ? (string) $data['dossier_title'] : null,
                'audioRecordingId' => isset($data['audio_recording_id']) ? (int) $data['audio_recording_id'] : null,
                'originalFilename' => $originalFilename,
            ],
        ];
    }
}
