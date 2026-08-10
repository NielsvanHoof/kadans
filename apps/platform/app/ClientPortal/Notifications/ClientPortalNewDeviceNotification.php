<?php

declare(strict_types=1);

namespace App\ClientPortal\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Tell the responsible staff member that a dossier's magic link was opened from
 * a device that did not open it originally.
 *
 * Inbox-only. This is a "worth a look" signal, not an incident: the link is
 * re-exchangeable by design and clients do switch devices. Mailing it would
 * train staff to ignore it.
 */
final class ClientPortalNewDeviceNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public const string DatabaseType = 'client_portal_new_device';

    public function __construct(
        public readonly int $tenantId,
        public readonly int $dossierId,
        public readonly string $dossierTitle,
        public readonly string $clientName,
        public readonly string $dossierUrl,
    ) {
        $this->afterCommit();
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * Telescope tags for the queued notification job.
     *
     * @return list<string>
     */
    public function tags(): array
    {
        return [
            'notification',
            self::DatabaseType,
            'tenant:'.$this->tenantId,
            'dossier:'.$this->dossierId,
        ];
    }

    public function databaseType(object $notifiable): string
    {
        return self::DatabaseType;
    }

    /**
     * @return array{
     *     tenant_id: string,
     *     type: string,
     *     dossier_id: int,
     *     dossier_title: string,
     *     client_name: string,
     *     dossier_url: string
     * }
     */
    public function toArray(object $notifiable): array
    {
        return [
            'tenant_id' => (string) $this->tenantId,
            'type' => self::DatabaseType,
            'dossier_id' => $this->dossierId,
            'dossier_title' => $this->dossierTitle,
            'client_name' => $this->clientName,
            'dossier_url' => $this->dossierUrl,
        ];
    }
}
