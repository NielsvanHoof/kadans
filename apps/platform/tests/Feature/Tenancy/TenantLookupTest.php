<?php

declare(strict_types=1);

use App\Tenancy\Support\Workspace\TenantLookup;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

covers(TenantLookup::class);

/**
 * @return list<string>
 */
function tenantRowQueries(callable $body): array
{
    /** @var list<string> $matched */
    $matched = [];

    DB::listen(function (QueryExecuted $query) use (&$matched): void {
        $sql = mb_strtolower($query->sql);

        if (str_contains($sql, 'from "tenants"') || str_contains($sql, 'from `tenants`')) {
            $matched[] = $query->sql;
        }
    });

    $body();

    return $matched;
}

test('a second slug lookup in one request does not query tenants', function (): void {
    ['tenant' => $tenant] = provisionWorkspace();

    $first = TenantLookup::findBySlug($tenant->slug);

    $queries = tenantRowQueries(
        fn (): mixed => TenantLookup::findBySlug($tenant->slug),
    );

    expect($first)->not->toBeNull()
        ->and($first?->id)->toBe($tenant->id)
        ->and($queries)
        ->toBeEmpty()
        ->and(Cache::has(TenantLookup::slugKey($tenant->slug)))->toBeTrue();
});

test('a second id lookup in one request does not query tenants', function (): void {
    ['tenant' => $tenant] = provisionWorkspace();

    TenantLookup::findById($tenant->id);

    $queries = tenantRowQueries(
        fn (): mixed => TenantLookup::findById($tenant->id),
    );

    expect($queries)
        ->toBeEmpty();
});

test('updating a slug forgets the old directory key', function (): void {
    ['tenant' => $tenant] = provisionWorkspace();
    $oldSlug = $tenant->slug;

    TenantLookup::findBySlug($oldSlug);
    TenantLookup::findById($tenant->id);

    expect(Cache::has(TenantLookup::slugKey($oldSlug)))->toBeTrue()
        ->and(Cache::has(TenantLookup::idKey($tenant->id)))->toBeTrue();

    $tenant->update(['slug' => 'renamed-firm']);

    expect(Cache::has(TenantLookup::slugKey($oldSlug)))->toBeFalse()
        ->and(Cache::has(TenantLookup::slugKey('renamed-firm')))->toBeFalse()
        ->and(Cache::has(TenantLookup::idKey($tenant->id)))->toBeFalse();

    $fresh = TenantLookup::findBySlug('renamed-firm');

    expect($fresh)->not->toBeNull()
        ->and($fresh?->id)->toBe($tenant->id)
        ->and($fresh?->slug)->toBe('renamed-firm');
});
