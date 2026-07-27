<?php

declare(strict_types=1);

namespace App\Actions\Platform;

use App\Actions\Audit\LogAuditActivityAction;
use App\Enums\AuditEvent;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Platform\SupportSession;
use App\Support\Platform\SupportSessionData;

/**
 * End an active support session and audit the exit.
 */
final class EndSupportSessionAction
{
    public function __construct(
        private readonly SupportSession $supportSession,
        private readonly LogAuditActivityAction $logAuditActivity,
    ) {}

    public function handle(?User $user = null, AuditEvent $event = AuditEvent::SupportSessionEnded): ?SupportSessionData
    {
        $peek = $this->supportSession->peek();
        $ended = $this->supportSession->end();

        if (! $peek instanceof SupportSessionData) {
            return null;
        }

        $tenant = Tenant::query()->find($peek->tenantId);

        if ($tenant instanceof Tenant) {
            $tenant->makeCurrent();

            $this->logAuditActivity->handle(
                event: $event,
                subject: $tenant,
                properties: [
                    'reason' => $peek->reason,
                    'expires_at' => $peek->expiresAt->toIso8601String(),
                    'mode' => 'read_only',
                ],
                causer: $user,
            );
        }

        return $ended ?? $peek;
    }
}
