<?php

declare(strict_types=1);

namespace App\Tenancy\Concerns;

use App\Tenancy\Models\Tenant;
use App\Tenancy\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * Tenant-owned Eloquent models.
 *
 * Queries are filtered by TenantScope. Prefer queryWithoutTenantScope() for intentional
 * cross-tenant lookups (portal tokens, OCR callbacks, invitations) — do not use
 * withoutGlobalScopes(), which also drops SoftDeletes and other scopes.
 *
 * Writes refuse a tenant_id that does not match the current tenant. Factories may still
 * mass-assign tenant_id when it matches (or when no tenant is current yet and they nest
 * Tenant::factory()). Cross-tenant fixtures must be built under that tenant's context
 * (actingInWorkspace / TenantContext::runForTenant), not by smuggling another id.
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function (Model $model): void {
            $current = Tenant::current();
            $assigned = $model->getAttributes()['tenant_id'] ?? null;

            if ($assigned === null || $assigned === '') {
                throw_unless($current instanceof Tenant, RuntimeException::class, 'Cannot create tenant-owned records without an active tenant.');

                $model->setAttribute('tenant_id', $current->getKey());

                return;
            }

            // Smuggled or mistaken cross-tenant mass assignment — fail loudly.
            throw_if($current instanceof Tenant && (string) $assigned !== (string) $current->getKey(), RuntimeException::class, 'Cannot create a tenant-owned record for a different tenant than the current one.');
        });

        // tenant_id is fixed at create; reassignment would move the row between firms.
        static::updating(function (Model $model): void {
            throw_if($model->isDirty('tenant_id'), RuntimeException::class, 'tenant_id cannot be changed after create.');
        });
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
