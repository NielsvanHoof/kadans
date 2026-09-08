<?php

declare(strict_types=1);

namespace App\Tenancy\Support;

use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\Cache;

use function is_array;
use function is_string;
use function sprintf;

/**
 * Cached tenant-row lookup for HTTP middleware.
 *
 * Middleware runs again on every Inertia deferred-prop request, so a live
 * `tenants` query on slug/id would hit Postgres twice per page. Redis plus
 * `Cache::memo()` keeps the second lookup in-process and later round-trips
 * off Postgres.
 *
 * Do not use `once()` or a static: Octane flushes `once()` on
 * OperationTerminated, and a static would leak across requests. Memberships
 * stay live — that is authorization, not a directory.
 */
final class TenantLookup
{
    private const int TTL_SECONDS = 300;

    public static function findBySlug(string $slug): ?Tenant
    {
        return self::hydrate(
            Cache::memo()->remember(
                self::slugKey($slug),
                self::TTL_SECONDS,
                function () use ($slug): ?array {
                    $tenant = Tenant::query()->where('slug', $slug)->first();

                    return self::attributes($tenant);
                },
            ),
        );
    }

    public static function findById(int $id): ?Tenant
    {
        return self::hydrate(
            Cache::memo()->remember(
                self::idKey($id),
                self::TTL_SECONDS,
                function () use ($id): ?array {
                    $tenant = Tenant::query()->whereKey($id)->first();

                    return self::attributes($tenant);
                },
            ),
        );
    }

    /**
     * Drop both directory keys. Slug changes are rare; the old slug key
     * must still go, or a renamed firm 404s for the rest of the TTL.
     */
    public static function forget(Tenant $tenant): void
    {
        $slugs = [];

        foreach ([$tenant->slug, $tenant->getOriginal('slug')] as $slug) {
            if (is_string($slug) && $slug !== '') {
                $slugs[$slug] = true;
            }
        }

        foreach (array_keys($slugs) as $slug) {
            Cache::memo()->forget(self::slugKey($slug));
        }

        Cache::memo()->forget(self::idKey($tenant->id));
    }

    public static function slugKey(string $slug): string
    {
        return sprintf('tenant:slug:%s', $slug);
    }

    public static function idKey(int $id): string
    {
        return sprintf('tenant:id:%d', $id);
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function attributes(?Tenant $tenant): ?array
    {
        return $tenant instanceof Tenant ? $tenant->getAttributes() : null;
    }

    private static function hydrate(mixed $cached): ?Tenant
    {
        if (! is_array($cached) || $cached === []) {
            return null;
        }

        /** @var array<string, mixed> $cached */
        return (new Tenant)->newFromBuilder($cached);
    }
}
