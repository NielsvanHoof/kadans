<?php

declare(strict_types=1);

namespace App\Shared\Queries\Filters;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;
use InvalidArgumentException;
use Laravel\Scout\Builder as ScoutBuilder;
use Spatie\QueryBuilder\AllowedFilter;

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

        // DatabaseEngine applies no SQL LIMIT when builder limit is null.
        $scoutBuilder->take(Config::integer('scout.key_limit'));

        $keys = [];

        foreach ($scoutBuilder->keys() as $key) {
            if (is_int($key) || is_string($key)) {
                $keys[] = $key;
            }
        }

        return $keys;
    }
}
