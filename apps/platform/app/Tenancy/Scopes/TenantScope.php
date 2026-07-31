<?php

declare(strict_types=1);

namespace App\Tenancy\Scopes;

use App\Tenancy\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use RuntimeException;

/**
 * Isolates BelongsToTenant models to the current tenant.
 *
 * Soft by default: with no current tenant, the query matches nothing (no leak).
 * Strict mode (opt-in via TenantContext) throws instead — used on workspace/portal
 * requests where a tenant must already be current.
 */
final class TenantScope implements Scope
{
    /**
     * @param  Builder<Model>  $builder
     */
    public function apply(Builder $builder, Model $model): void
    {
        $tenant = Tenant::current();

        if ($tenant instanceof Tenant) {
            $builder->where(
                $builder->qualifyColumn('tenant_id'),
                $tenant->getKey(),
            );

            return;
        }

        throw_if(resolve(TenantContext::class)->isStrict(), RuntimeException::class, 'Cannot query tenant-owned records without an active tenant.');

        // Soft default: empty result set so accidental queries do not cross tenants.
        $builder->whereRaw('0 = 1');
    }
}
