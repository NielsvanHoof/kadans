<?php

declare(strict_types=1);

namespace App\Queries\Platform;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedSort;
use Spatie\QueryBuilder\QueryBuilder;

use function is_string;

/**
 * Searchable firm list for platform support sessions.
 */
final class FetchPlatformSupportTenantsQuery
{
    private const int Limit = 50;

    /**
     * @return list<array{id: int, name: string, slug: string}>
     */
    public function handle(): array
    {
        // Prefer Model::select() so phpstan-strict-rules does not flag instance->select().
        $builder = QueryBuilder::for(
            Tenant::select(['id', 'name', 'slug']),
        )
            // Spatie Query Builder takes variadic AllowedFilter|string args.
            ->allowedFilters(...$this->allowedFilters())
            ->allowedSorts(
                AllowedSort::field('name'),
                AllowedSort::field('slug'),
            )
            ->defaultSort('name');

        // Cap via the base query to avoid Larastan staticMethod.dynamicCall.
        $builder->getQuery()->limit(self::Limit);

        // array_values() guarantees a list for the PHPDoc return type.
        return array_values($builder
            ->get()
            ->map(static fn (Tenant $tenant): array => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
            ])
            ->all());
    }

    /**
     * @return list<AllowedFilter>
     */
    private function allowedFilters(): array
    {
        return [
            AllowedFilter::callback('q', function (Builder $query, mixed $value): void {
                if (! is_string($value) || mb_trim($value) === '') {
                    return;
                }

                $search = mb_trim($value);

                // Base query avoids Larastan staticMethod.dynamicCall on where/orWhere.
                $query->getQuery()->where(function ($nestedQuery) use ($search): void {
                    $nestedQuery
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('slug', 'like', "%{$search}%");
                });
            }),
        ];
    }
}
