<?php

declare(strict_types=1);

namespace App\Reporting\Concerns;

use App\Reporting\Support\WorkspaceDashboardCache;
use Illuminate\Database\Eloquent\Model;

use function is_numeric;

/**
 * Forget the workspace dashboard metrics cache when a metric-driving model changes.
 *
 * Reads tenant_id from the model itself (not Tenant::current()) so portal
 * and queued writes still invalidate the right firm.
 */
trait InvalidatesWorkspaceDashboardCache
{
    public static function bootInvalidatesWorkspaceDashboardCache(): void
    {
        // restored / forceDeleted only fire on SoftDeletes models; harmless elsewhere.
        foreach (['saved', 'deleted', 'restored', 'forceDeleted'] as $event) {
            static::registerModelEvent($event, function (Model $model): void {
                $tenantId = $model->getAttribute('tenant_id');

                // Accept numeric strings from PDO as well as ints from factories.
                if (! is_numeric($tenantId)) {
                    return;
                }

                WorkspaceDashboardCache::forget((int) $tenantId);
            });
        }
    }
}
