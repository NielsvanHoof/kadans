<?php

declare(strict_types=1);

namespace App\Platform\Support\SupportSession;

use App\Accounts\Models\User;
use App\Audit\Enums\AuditEvent;
use App\Platform\Actions\SupportSession\EndSupportSessionAction;
use App\Tenancy\Contracts\SupportSessionState;
use App\Tenancy\Contracts\TenantPermission;
use App\Tenancy\Models\Tenant;

/**
 * Platform's implementation of the Tenancy-facing support session contract.
 *
 * Keeps the expiry/audit sequence (peek, check, end, log) inside Platform so
 * Tenancy never has to know about SupportSessionData or EndSupportSessionAction.
 */
final readonly class PlatformSupportSessionState implements SupportSessionState
{
    public function __construct(
        private SupportSession $supportSession,
        private EndSupportSessionAction $endSupportSession,
    ) {}

    public function isActiveFor(Tenant $tenant): bool
    {
        return $this->supportSession->isActiveFor($tenant);
    }

    public function allowsPermission(TenantPermission|string $permission): bool
    {
        return $this->supportSession->allowsPermission($permission);
    }

    public function clearIfExpired(User $user): bool
    {
        $peek = $this->supportSession->peek();

        if (! $peek instanceof SupportSessionData || ! $peek->hasExpired()) {
            return false;
        }

        $this->endSupportSession->handle(
            user: $user,
            event: AuditEvent::SupportSessionExpired,
        );

        return true;
    }
}
