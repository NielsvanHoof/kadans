<?php

declare(strict_types=1);

namespace App\ClientPortal\Notifications\Outreach;

use App\Accounts\Support\Localization\DisplayTimezones;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Email a client their standing portal link.
 *
 * Says nothing about a particular dossier, because the link is not about one
 * and what sits behind it changes over time. It names the firm and how much is
 * waiting right now, which is the part the client can act on.
 */
final class ClientPortalLinkInviteNotification extends Notification implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $grantId,
        public string $clientName,
        public string $portalUrl,
        public CarbonInterface $expiresAt,
        public string $firmName,
        public int $waitingCount,
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
            'client_portal_link_invite',
            'grant:'.$this->grantId,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject(__('Your documents for :firm', ['firm' => $this->firmName]))
            ->greeting(__('Hello :name,', ['name' => $this->clientName]))
            ->line($this->waitingCount > 0
                ? __(':firm is waiting for information from you. Everything they need is behind one link.', [
                    'firm' => $this->firmName,
                ])
                : __(':firm has set up a secure page where you can see anything they need from you.', [
                    'firm' => $this->firmName,
                ]));

        return $message
            ->line(__('Open it with the button below. No account or password is required, and the same link keeps working as new requests are added.'))
            ->action(__('Open your documents'), $this->portalUrl)
            ->line(__('This link expires on :expires.', [
                'expires' => $this->expiresAt
                    ->timezone(DisplayTimezones::default())
                    ->format('j F Y, H:i (T)'),
            ]))
            ->line(__('If you did not expect this email, you can ignore it.'))
            ->tag('client_portal_link_invite')
            ->metadata('grant_id', (string) $this->grantId);
    }
}
