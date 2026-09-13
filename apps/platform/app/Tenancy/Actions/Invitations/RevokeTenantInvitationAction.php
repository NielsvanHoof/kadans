<?php

declare(strict_types=1);

namespace App\Tenancy\Actions\Invitations;

use App\Accounts\Models\User;
use App\Audit\Actions\LogAuditActivityAction;
use App\Audit\Enums\AuditEvent;
use App\Tenancy\Models\TenantInvitation;
use Illuminate\Validation\ValidationException;

final readonly class RevokeTenantInvitationAction
{
    public function __construct(private LogAuditActivityAction $logAuditActivity) {}

    public function handle(TenantInvitation $invitation, User $revokedBy): void
    {
        if ($invitation->isAccepted()) {
            throw ValidationException::withMessages([
                'invitation' => __('This invitation has already been accepted.'),
            ]);
        }

        if ($invitation->isRevoked()) {
            return;
        }

        $invitation->forceFill(['revoked_at' => now()])->saveOrFail();

        $this->logAuditActivity->handle(
            AuditEvent::MemberInvitationRevoked,
            $invitation,
            [
                'email' => $invitation->email,
                'role' => $invitation->role,
            ],
            $revokedBy,
        );
    }
}
