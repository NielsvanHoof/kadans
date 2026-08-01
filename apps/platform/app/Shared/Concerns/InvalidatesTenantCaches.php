<?php

declare(strict_types=1);

namespace App\Shared\Concerns;

use App\Shared\Cache\TenantCacheInvalidator;
use Illuminate\Database\Eloquent\Model;

use function is_numeric;

/**
 * Forget tenant-scoped derived caches when this model changes.
 *
 * Reads tenant_id from the model itself (not Tenant::current()) so portal and
 * queued writes still invalidate the right firm.
 *
 * @see TenantCacheInvalidator for why the dependency is inverted.
 */
trait InvalidatesTenantCaches
{
    public static function bootInvalidatesTenantCaches(): void
    {
        // restored / forceDeleted only fire on SoftDeletes models; harmless elsewhere.
        foreach (['saved', 'deleted', 'restored', 'forceDeleted'] as $event) {
            static::registerModelEvent($event, function (Model $model): void {
                $tenantId = $model->getAttribute('tenant_id');

                // Accept numeric strings from PDO as well as ints from factories.
                if (! is_numeric($tenantId)) {
                    return;
                }

                resolve(TenantCacheInvalidator::class)->forgetForTenant((int) $tenantId);
            });
        }
    }
}
