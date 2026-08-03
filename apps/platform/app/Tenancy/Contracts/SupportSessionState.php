<?php

declare(strict_types=1);

namespace App\Tenancy\Contracts;

use App\Accounts\Models\User;
use App\Tenancy\Models\Tenant;

/**
 * Read-only platform support access, as seen from Tenancy.
 *
 * Tenancy sits below Platform in the domain layering, so it must not reference
 * the Platform namespace directly. Platform implements this contract and binds
 * it in PlatformServiceProvider; when no implementation is bound (e.g. a
 * Tenancy-only test context) nothing here should be assumed to exist.
 */
interface SupportSessionState
{
    /**
     * Is a read-only support session currently active for this tenant?
     */
    public function isActiveFor(Tenant $tenant): bool;

    /**
     * May the active support session exercise this permission?
     */
    public function allowsPermission(TenantPermission|string $permission): bool;

    /**
     * Clear and audit an expired support session.
     *
     * @return bool True when an expired session was cleared.
     */
    public function clearIfExpired(User $user): bool;
}
