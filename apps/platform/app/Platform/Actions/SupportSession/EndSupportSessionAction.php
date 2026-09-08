<?php

declare(strict_types=1);

namespace App\Platform\Actions\SupportSession;

use App\Accounts\Models\User;
use App\Audit\Actions\LogAuditActivityAction;
use App\Audit\Enums\AuditEvent;
use App\Platform\Support\SupportSession\SupportSession;
use App\Platform\Support\SupportSession\SupportSessionData;
use App\Tenancy\Models\Tenant;

/**
 * End an active support session and audit the exit.
 */
final readonly class EndSupportSessionAction
{
    public function __construct(
        private SupportSession $supportSession,
        private LogAuditActivityAction $logAuditActivity,
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
