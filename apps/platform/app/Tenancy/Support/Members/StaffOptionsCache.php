<?php

declare(strict_types=1);

namespace App\Tenancy\Support\Members;

use Closure;
use Illuminate\Support\Facades\Cache;

use function is_array;
use function sprintf;

/**
 * Tenant-scoped cache for the staff-options dropdown.
 *
 * Keys include the tenant id because PrefixCacheTask is disabled.
 *
 * Do not invalidate from TenantMembership model events: last_accessed_at
 * writes every five minutes and would thrash this cache. Forget from the
 * membership write actions instead.
 */
final class StaffOptionsCache
{
    private const int TTL_SECONDS = 300;

    private const int FRESH_SECONDS = 120;

    /**
     * Bump when the stored array shape changes.
     */
    private const string SCHEMA_VERSION = 'v1';

    /**
     * @param  Closure(): list<array{id: int, name: string, email: string}>  $callback
     * @return list<array{id: int, name: string, email: string}>
     */
    public static function remember(int $tenantId, Closure $callback): array
    {
        // The callback must stay a closure. `$callback()` as a first-class
        // callable receiver would run the query on every call, hit or miss.
        $cached = Cache::flexible(
            self::key($tenantId),
            [self::FRESH_SECONDS, self::TTL_SECONDS],
            static fn (): array => $callback(),
        );

        if (! is_array($cached)) {
            return $callback();
        }

        /** @var list<array{id: int, name: string, email: string}> $cached */
        return $cached;
    }

    public static function forget(int $tenantId): void
    {
        Cache::forget(self::key($tenantId));
    }

    public static function key(int $tenantId): string
    {
        return sprintf('staff-options:%s:%d', self::SCHEMA_VERSION, $tenantId);
    }
}
