<?php

declare(strict_types=1);

namespace App\Shared\Queries\Filters;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;
use InvalidArgumentException;
use Laravel\Scout\Builder as ScoutBuilder;
use Spatie\QueryBuilder\AllowedFilter;

use function array_slice;
use function count;
use function is_callable;
use function is_int;
use function is_string;

/**
 * Spatie AllowedFilter that constrains an Eloquent query to Scout search hits.
 *
 * Works with Scout's database engine (pgsql/mysql) and collection engine (tests/sqlite).
 */
final class ScoutSearchFilter
{
    /**
     * Request attribute set when a search hit the scout.key_limit cap.
     * PaginatedIndexQuery reads this into indexQuery.search_truncated.
     */
    public const string REQUEST_ATTRIBUTE_TRUNCATED = 'scout_search_truncated';

    /**
     * @param  class-string<Model>  $modelClass
     * @param  array<string, int|string>  $wheres  Scout equality filters (table columns / index fields)
     */
    public static function make(
        string $modelClass,
        string $name = 'q',
        bool $onlyTrashed = false,
        array $wheres = [],
    ): AllowedFilter {
        return AllowedFilter::callback(
            $name,
            function (Builder $query, mixed $value) use ($modelClass, $onlyTrashed, $wheres): void {
                if (! is_string($value) || mb_trim($value) === '') {
                    return;
                }

                // Scout returns matching primary keys; apply them to the current builder
                // so Spatie filters/sorts/pagination keep working on the same query.
                $ids = self::searchKeys($modelClass, mb_trim($value), $onlyTrashed, $wheres);

                // whereKey([]) yields an empty result set (no matching primary keys).
                $query->whereKey($ids);
            },
        );
    }

    /**
     * @param  class-string<Model>  $modelClass
     * @param  array<string, int|string>  $wheres
     * @return list<int|string>
     */
    private static function searchKeys(string $modelClass, string $value, bool $onlyTrashed, array $wheres): array
    {
        $search = [$modelClass, 'search'];

        throw_unless(is_callable($search), InvalidArgumentException::class, "{$modelClass} is not searchable via Scout.");

        $scoutBuilder = $search($value);

        throw_unless($scoutBuilder instanceof ScoutBuilder, InvalidArgumentException::class, "{$modelClass}::search() must return a Scout builder.");

        foreach ($wheres as $field => $fieldValue) {
            $scoutBuilder->where($field, $fieldValue);
        }

        if ($onlyTrashed) {
            $scoutBuilder->onlyTrashed();
        }

        // Bound the IN list (Postgres bind limit) and detect truncation with one
        // extra key — DatabaseEngine hydrates every match when take() is null.
        $limit = Config::integer('scout.key_limit');
        $scoutBuilder->take($limit + 1);

        $keys = [];

        foreach ($scoutBuilder->keys() as $key) {
            if (is_int($key) || is_string($key)) {
                $keys[] = $key;
            }
        }

        if (count($keys) > $limit) {
            request()->attributes->set(self::REQUEST_ATTRIBUTE_TRUNCATED, true);

            return array_values(array_slice($keys, 0, $limit));
        }

        return $keys;
    }
}
