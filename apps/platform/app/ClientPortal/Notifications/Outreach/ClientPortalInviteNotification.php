<?php

declare(strict_types=1);

namespace App\ClientPortal\Notifications;

use App\Accounts\Support\DisplayTimezones;
use App\Clients\Models\Client;
use App\Dossiers\Models\Dossier;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use RuntimeException;

final class ClientPortalInviteNotification extends Notification implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $grantId,
        public Dossier $dossier,
        public string $portalUrl,
        public CarbonInterface $expiresAt,
        public string $firmName,
    ) {
        $this->afterCommit();
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
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
            'mail',
            'client_portal_invite',
            'grant:'.$this->grantId,
            'dossier:'.$this->dossier->id,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $client = $this->dossier->client;

        throw_unless($client instanceof Client, RuntimeException::class, 'Dossier client is missing.');

        return (new MailMessage)
            ->subject(__(':firm: documents requested for :title', [
                'firm' => $this->firmName,
                'title' => $this->dossier->title,
            ]))
            ->greeting(__('Hello :name,', [
                'name' => $client->name,
            ]))
            ->line(__(':firm has asked you to upload documents for “:title”.', [
                'firm' => $this->firmName,
                'title' => $this->dossier->title,
            ]))
            ->line(__('Open the secure portal with the button below. No account or password is required.'))
            ->action(__('Open document portal'), $this->portalUrl)
            ->line(__('This link expires on :expires.', [
                'expires' => $this->expiresAt
                    ->timezone(DisplayTimezones::default())
                    ->format('j F Y, H:i (T)'),
            ]))
            ->line(__('If you did not expect this email, you can ignore it.'))
            ->tag('client_portal_invite')
            ->metadata('grant_id', (string) $this->grantId)
            ->metadata('dossier_id', (string) $this->dossier->id);
    }
}
