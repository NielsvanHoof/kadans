<?php

declare(strict_types=1);

namespace App\Tenancy\Http\Middleware;

use App\Accounts\Models\User;
use App\Tenancy\Contracts\SupportSessionState;
use App\Tenancy\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class EnsureValidTenantMembership
{
    public function __construct(private SupportSessionState $supportSession) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $tenant = Tenant::current();

        abort_if(! $user instanceof User || ! $tenant instanceof Tenant, Response::HTTP_FORBIDDEN);

        // Clear and audit expired support sessions before access checks.
        $this->supportSession->clearIfExpired($user);

        $user->loadMissing('tenantMemberships.tenant');

        abort_unless($user->canAccessTenant($tenant), Response::HTTP_FORBIDDEN);

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
}
