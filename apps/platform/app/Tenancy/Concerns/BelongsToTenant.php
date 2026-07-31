<?php

declare(strict_types=1);

namespace App\Tenancy\Concerns;

use App\Tenancy\Models\Tenant;
use App\Tenancy\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * Tenant-owned Eloquent models.
 *
 * Queries are filtered by TenantScope. Prefer withoutTenantScope() for intentional
 * cross-tenant lookups (portal tokens, OCR callbacks, invitations) — do not use
 * withoutGlobalScopes(), which also drops SoftDeletes and other scopes.
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function (Model $model): void {
            if (! isset($model->getAttributes()['tenant_id'])) {
                $tenant = Tenant::current();

                throw_unless($tenant instanceof Tenant, RuntimeException::class, 'Cannot create tenant-owned records without an active tenant.');

                $model->setAttribute('tenant_id', $tenant->getKey());
            }
        });
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Drop only the tenant isolation scope — keeps SoftDeletes and others.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    #[Scope]
    protected function withoutTenantScope(Builder $query): Builder
    {
        return $query->withoutGlobalScope(TenantScope::class);
    }
}
