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
 * Only writes that can actually move a cached number evict. This used to fire on
 * every `saved`, which meant renaming a client, retitling a dossier or reordering
 * a questionnaire item all threw away the sixteen aggregate counts behind the
 * dashboard — and `saved` fires even when nothing changed at all. DocumentRequest
 * is the model that made this matter: it is written on every client answer and
 * every staff review, so the 300s cache was rarely warm on a busy firm.
 *
 * Creates, deletes, restores and force-deletes always evict, because they change
 * a row count no matter which columns they touch.
 *
 * @see TenantCacheInvalidator for why the dependency is inverted.
 */
trait InvalidatesTenantCaches
{
    /**
     * Attributes whose value feeds a cached tenant metric.
     *
     * An empty list means no update to this model can move one — only its
     * existence counts.
     *
     * @return list<string>
     */
    abstract protected static function cacheInvalidatingAttributes(): array;

    public static function bootInvalidatesTenantCaches(): void
    {
        static::registerModelEvent('updated', function (Model $model): void {
            $attributes = static::cacheInvalidatingAttributes();

            // wasChanged() with an empty list reports "anything changed", which
            // is the opposite of what an empty list means here.
            if ($attributes === [] || ! $model->wasChanged($attributes)) {
                return;
            }

            self::forgetTenantCaches($model);
        });

        // `created` rather than `saved` plus a wasRecentlyCreated check: that
        // flag stays true for the rest of the instance's life, so a model
        // created and then updated in the same request would take the insert
        // branch on the update too.
        //
        // restored / forceDeleted only fire on SoftDeletes models; harmless elsewhere.
        foreach (['created', 'deleted', 'restored', 'forceDeleted'] as $event) {
            static::registerModelEvent($event, self::forgetTenantCaches(...));
        }
    }

    private static function forgetTenantCaches(Model $model): void
    {
        $tenantId = $model->getAttribute('tenant_id');

        // Accept numeric strings from PDO as well as ints from factories.
        if (! is_numeric($tenantId)) {
            return;
        }

        resolve(TenantCacheInvalidator::class)->forgetForTenant((int) $tenantId);
    }
}
