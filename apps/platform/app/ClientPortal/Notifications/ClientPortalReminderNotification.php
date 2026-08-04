<?php

declare(strict_types=1);

namespace App\ClientPortal\Notifications;

use App\Clients\Models\Client;
use App\Dossiers\Enums\DossierReminderSource;
use App\Dossiers\Models\Dossier;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use RuntimeException;

final class ClientPortalReminderNotification extends Notification implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $grantId,
        public Dossier $dossier,
        public string $portalUrl,
        public CarbonInterface $expiresAt,
        public string $firmName,
        public DossierReminderSource $source,
        public ?string $customMessage = null,
    ) {
        $this->afterCommit();
    }

    /** @return list<string> */
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
            'client_portal_reminder',
            'grant:'.$this->grantId,
            'dossier:'.$this->dossier->id,
            'reminder_source:'.$this->source->value,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $client = $this->dossier->client;

        throw_unless($client instanceof Client, RuntimeException::class, 'Dossier client is missing.');

        $message = (new MailMessage)
            ->subject(__(':firm: reminder for :title', [
                'firm' => $this->firmName,
                'title' => $this->dossier->title,
            ]))
            ->greeting(__('Hello :name,', ['name' => $client->name]));

        if ($this->customMessage !== null) {
            $message->line($this->customMessage);
        } else {
            $message->line(__(':firm is still waiting for information for “:title”.', [
                'firm' => $this->firmName,
                'title' => $this->dossier->title,
            ]));

            if ($this->dossier->due_date !== null) {
                $message->line(__('Please complete the remaining items by :date.', [
                    'date' => $this->dossier->due_date->format('j F Y'),
                ]));
            }
        }

        return $message
            ->action(__('Continue in the secure portal'), $this->portalUrl)
            ->line(__('This new secure link expires on :expires.', [
                'expires' => $this->expiresAt->timezone(config('app.timezone'))->format('j F Y, H:i'),
            ]))
            ->line(__('If you have already provided everything, no further action is needed.'))
            ->tag('client_portal_reminder')
            ->metadata('grant_id', (string) $this->grantId)
            ->metadata('dossier_id', (string) $this->dossier->id)
            ->metadata('reminder_source', $this->source->value);
    }
}
