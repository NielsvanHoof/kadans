<?php

declare(strict_types=1);

namespace App\Tenancy\Http\Middleware;

use App\Accounts\Models\User;
use App\Tenancy\Contracts\SupportSessionState;
use App\Tenancy\Enums\TenantMembershipStatus;
use App\Tenancy\Models\Tenant;
use App\Tenancy\Models\TenantMembership;
use App\Tenancy\Support\TenantLookup;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

use function is_int;

final readonly class InitializeTenantFromSession
{
    public function __construct(private SupportSessionState $supportSession) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return $next($request);
        }

        $tenantId = $request->session()->get('active_tenant_id');

        if ($tenantId === null) {
            // Prefer the model query builder over the relation for strict PHPStan.
            $tenantIds = TenantMembership::query()
                ->where('user_id', $user->id)
                ->where('status', TenantMembershipStatus::Active)
                ->pluck('tenant_id');

            if ($tenantIds->count() !== 1) {
                return $next($request);
            }

            $tenantId = $tenantIds->sole();
        }

        if (! is_int($tenantId) && ! ctype_digit((string) $tenantId)) {
            return $next($request);
        }

        $tenant = TenantLookup::findById((int) $tenantId);

        if (! $tenant instanceof Tenant) {
            $request->session()->forget('active_tenant_id');

            return $next($request);
        }

        $user->loadMissing('tenantMemberships');

        $this->supportSession->clearIfExpired($user);

        // Allow session tenant when member OR active support session for that firm.
        if (! $user->canAccessTenant($tenant)) {
            // Drop stale active_tenant_id if support session expired / was cleared.
            if (! $user->belongsToTenant($tenant)) {
                $request->session()->forget('active_tenant_id');
            }

            return $next($request);
        }

        $tenant->makeCurrent();

        if ($request->session()->get('active_tenant_id') !== $tenant->id) {
            $request->session()->put('active_tenant_id', $tenant->id);
        }

        return $next($request);
    }
}
