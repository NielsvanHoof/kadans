<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Actions\Platform\EndSupportSessionAction;
use App\Enums\AuditEvent;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Platform\SupportSession;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureValidTenantMembership
{
    public function __construct(
        private readonly SupportSession $supportSession,
        private readonly EndSupportSessionAction $endSupportSession,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $tenant = Tenant::current();

        if (! $user instanceof User || ! $tenant instanceof Tenant) {
            abort(Response::HTTP_FORBIDDEN);
        }

        // Clear and audit expired support sessions before access checks.
        $this->clearExpiredSupportSession($user);

        $user->loadMissing('tenantMemberships.tenant');

        if (! $user->canAccessTenant($tenant)) {
            abort(Response::HTTP_FORBIDDEN);
        }

        // Only bump last_accessed_at for real memberships — not support sessions.
        if ($user->belongsToTenant($tenant)) {
            foreach ($user->tenantMemberships as $membership) {
                if ($membership->tenant_id === $tenant->getKey()) {
                    $membership->update(['last_accessed_at' => now()]);

                    break;
                }
            }
        }

        return $next($request);
    }

    private function clearExpiredSupportSession(User $user): void
    {
        $peek = $this->supportSession->peek();

        if ($peek === null || ! $peek->hasExpired()) {
            return;
        }

        $this->endSupportSession->handle(
            user: $user,
            event: AuditEvent::SupportSessionExpired,
        );
    }
}
