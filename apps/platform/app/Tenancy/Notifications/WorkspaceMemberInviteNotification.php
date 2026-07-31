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
                'app' => (string) config('app.name'),
            ]))
            ->greeting(__('You have been invited'))
            ->line(__(':firm invited you to join their workspace as :role.', [
                'firm' => $this->firmName,
                'role' => Role::tryFrom($this->role)?->label() ?? $this->role,
            ]))
            ->line(__('This invitation expires on :date.', [
                'date' => $this->expiresAt->timezone(config('app.timezone'))->toDayDateTimeString(),
            ]))
            ->action(__('Accept invitation'), $this->acceptUrl)
            ->line(__('If you did not expect this email, you can ignore it.'))
            ->tag('workspace_member_invite')
            ->metadata('invitation_id', (string) $this->invitationId);
    }
}
