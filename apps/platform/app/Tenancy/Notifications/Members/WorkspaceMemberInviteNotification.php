<?php

declare(strict_types=1);

namespace App\Tenancy\Notifications;

use App\Tenancy\Enums\Role;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Config;

final class WorkspaceMemberInviteNotification extends Notification implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public string $role;

    public function __construct(
        public int $invitationId,
        public string $firmName,
        Role|string $role,
        public string $acceptUrl,
        public CarbonInterface $expiresAt,
        // Resolved by the caller and carried on the job: a queued notification has
        // no request, so it cannot read the recipient's cookie.
        public string $displayTimezone = 'UTC',
    ) {
        $this->role = $role instanceof Role ? $role->value : $role;
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
            'workspace_member_invite',
            'invitation:'.$this->invitationId,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('Join :firm on :app', [
                'firm' => $this->firmName,
                'app' => Config::string('app.name'),
            ]))
            ->greeting(__('You have been invited'))
            ->line(__(':firm invited you to join their workspace as :role.', [
                'firm' => $this->firmName,
                'role' => Role::tryFrom($this->role)?->label() ?? $this->role,
            ]))
            // Zone abbreviation included — a recipient cannot act on a deadline
            // without knowing whose clock it is on.
            ->line(__('This invitation expires on :date.', [
                'date' => $this->expiresAt
                    ->timezone($this->displayTimezone)
                    ->format('j F Y, H:i (T)'),
            ]))
            ->action(__('Accept invitation'), $this->acceptUrl)
            ->line(__('If you did not expect this email, you can ignore it.'))
            ->tag('workspace_member_invite')
            ->metadata('invitation_id', (string) $this->invitationId);
    }
}
