<?php

declare(strict_types=1);

namespace App\Actions\Platform;

use App\Actions\Audit\LogAuditActivityAction;
use App\Enums\AuditEvent;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Platform\SupportSession;
use App\Support\Platform\SupportSessionData;
use InvalidArgumentException;

/**
 * Start a time-boxed read-only support session for a firm.
 */
final class StartSupportSessionAction
{
    public function __construct(
        private readonly SupportSession $supportSession,
        private readonly LogAuditActivityAction $logAuditActivity,
    ) {}

    public function handle(User $user, Tenant $tenant, string $reason): SupportSessionData
    {
        if (! $user->isPlatformSupport()) {
            throw new InvalidArgumentException('Only platform support users can start a support session.');
        }

        // Make tenant current so the audit row gets tenant_id stamped.
        $tenant->makeCurrent();

        $session = $this->supportSession->start($user, $tenant, $reason);

        $this->logAuditActivity->handle(
            event: AuditEvent::SupportSessionStarted,
            subject: $tenant,
            properties: [
                'reason' => $session->reason,
                'expires_at' => $session->expiresAt->toIso8601String(),
                'mode' => 'read_only',
            ],
            causer: $user,
        );

        return $session;
    }
}
