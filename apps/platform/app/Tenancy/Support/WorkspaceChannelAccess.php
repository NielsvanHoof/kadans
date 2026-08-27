<?php

declare(strict_types=1);

namespace App\Tenancy\Support;

use App\Accounts\Models\User;
use App\Tenancy\Models\Tenant;

/**
 * Membership rule shared by every workspace broadcast channel.
 *
 * A class rather than a helper in routes/channels.php: that file is required
 * again on each application boot, so a top-level function there fatals on
 * redeclaration as soon as a second app instance is created — which the test
 * suite does constantly.
 *
 * Keeping it in one place also stops the three workspace channels drifting
 * apart on who may subscribe. Same rules as EnsureValidTenantMembership.
 */
final class WorkspaceChannelAccess
{
    /**
     * The firm behind a slug, if this user is an active member of it.
     */
    public static function memberTenant(User $user, string $tenantSlug): ?Tenant
    {
        $tenant = TenantLookup::findBySlug($tenantSlug);

        if (! $tenant instanceof Tenant) {
            return null;
        }

        // Memberships stay live — that is authorization, not a directory.
        $user->loadMissing('tenantMemberships');

        return $user->belongsToTenant($tenant) ? $tenant : null;
    }
}
