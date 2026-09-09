<?php

declare(strict_types=1);

namespace App\Tenancy\Support\Settings;

use Closure;
use Illuminate\Support\Facades\Cache;

use function is_array;
use function sprintf;

/**
 * Tenant-scoped cache for the firm settings row.
 *
 * `Cache::memo()` rather than `Cache::flexible()`, for the reason TenantLookup
 * already gives: this is read from middleware, which runs again on every
 * Inertia deferred-prop request, so the win being harvested is many reads per
 * request collapsing to one — that is the in-process memo, not the Redis
 * round-trip. `flexible()` would also serve a deliberately stale value, which
 * is wrong for a row that decides two-factor enforcement and portal session
 * length: a firm that tightens a setting would keep being served the loose one.
 *
 * Stores the raw attribute array, never the resolved settings object. The
 * resolved form substitutes platform config for every null, so caching it would
 * bake config() into Redis for the TTL — and a deploy that *tightened* the
 * platform floor would be ignored until the key expired.
 *
 * Keys include the tenant id because PrefixCacheTask is disabled.
 */
final class TenantSettingsCache
{
    private const int TTL_SECONDS = 300;

    /**
     * Bump whenever a column is added to `tenant_settings`.
     *
     * An array cached before a migration lacks the new keys, and a missing key
     * reads as null — which this feature defines as "inherit the platform
     * value". Without a bump a firm's saved override would be silently ignored
     * for the rest of the TTL instead of failing loudly.
     */
    private const string SCHEMA_VERSION = 'v2';

    /**
     * @param  Closure(): ?array<string, mixed>  $callback
     * @return array<string, mixed>|null
     */
    public static function remember(int $tenantId, Closure $callback): ?array
    {
        // Must stay a closure: `$callback()` as a first-class callable receiver
        // would run the query on every call, hit or miss.
        $cached = Cache::memo()->remember(
            self::key($tenantId),
            self::TTL_SECONDS,
            static fn (): array => $callback() ?? [],
        );

        if (! is_array($cached) || $cached === []) {
            return null;
        }

        /** @var array<string, mixed> $cached */
        return $cached;
    }

    /**
     * Called from a TenantSetting model event, so it cannot be forgotten by a
     * future writer. A tenant delete cascades in the database and fires no
     * Eloquent event, leaving a key behind until it expires — harmless, since
     * nothing can resolve a deleted tenant.
     */
    public static function forget(int $tenantId): void
    {
        Cache::memo()->forget(self::key($tenantId));
    }

    public static function key(int $tenantId): string
    {
        return sprintf('tenant-settings:%s:%d', self::SCHEMA_VERSION, $tenantId);
    }
}
