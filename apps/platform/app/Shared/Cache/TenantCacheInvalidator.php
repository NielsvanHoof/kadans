<?php

declare(strict_types=1);

namespace App\Shared\Cache;

/**
 * Forget tenant-scoped derived caches after a write.
 *
 * Cache *policy* belongs to whichever domain owns the derived data (today only
 * Reporting's dashboard metrics), but the *trigger* is a write in a lower-layer
 * model. Without this contract, Client and Dossier would have to reference
 * Reporting directly — a dependency pointing the wrong way up the stack.
 *
 * Implementations are bound in the owning domain's service provider.
 */
interface TenantCacheInvalidator
{
    public function forgetForTenant(int $tenantId): void;
}
