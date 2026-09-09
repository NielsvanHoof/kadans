<?php

declare(strict_types=1);

namespace App\Tenancy\Actions\Settings;

use App\Tenancy\Models\Tenant;

/**
 * Change a firm's display name and the address its workspace lives at.
 *
 * Deliberately mutates the instance it is given rather than reloading one: the
 * caller is holding the route-bound tenant, so updating it in place is what
 * lets the redirect resolve to the new address instead of the old one.
 *
 * The directory cache needs no attention here. Tenant::booted() forgets it on
 * `updated`, and TenantLookup::forget() drops the previous slug's key as well
 * as the new one — without that, the old address would keep resolving until
 * the entry expired.
 */
final readonly class RenameTenantAction
{
    public function handle(Tenant $tenant, string $name, string $slug): Tenant
    {
        $tenant->update([
            'name' => $name,
            'slug' => $slug,
        ]);

        $this->rebindAsCurrentTenant($tenant);

        return $tenant;
    }

    /**
     * Replace the current-tenant binding with the renamed record.
     *
     * The multitenancy package binds the current tenant with
     * `app()->instance()`, and `makeCurrent()` returns early whenever the ids
     * match. On Octane the container outlives the request, so without this the
     * worker keeps serving the firm's *previous* name from the object it bound
     * before the rename — on every later request for the same firm, until it
     * happens to serve a different one. Forgetting first is what makes the
     * second call actually rebind.
     */
    private function rebindAsCurrentTenant(Tenant $tenant): void
    {
        if (! $tenant->isCurrent()) {
            return;
        }

        Tenant::forgetCurrent();
        $tenant->makeCurrent();
    }
}
