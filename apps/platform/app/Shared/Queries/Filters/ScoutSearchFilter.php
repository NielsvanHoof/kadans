<?php

declare(strict_types=1);

namespace App\Shared\Queries\Filters;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Laravel\Scout\Builder as ScoutBuilder;
use Spatie\QueryBuilder\AllowedFilter;
use Throwable;

use function array_slice;
use function count;
use function is_callable;
use function is_int;
use function is_string;

/**
 * Spatie AllowedFilter that constrains an Eloquent query to Scout search hits.
 *
 * Works with Scout's database engine (pgsql/mysql) and collection engine (tests/sqlite).
 * hybrid() is database-only — collection has no pgvector.
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
        bool $hybrid = false,
    ): AllowedFilter {
        return AllowedFilter::callback(
            $name,
            function (Builder $query, mixed $value) use ($modelClass, $onlyTrashed, $wheres, $hybrid): void {
                if (! is_string($value) || mb_trim($value) === '') {
                    return;
                }

                $ids = self::keys($modelClass, mb_trim($value), $onlyTrashed, $wheres, $hybrid);

                $query->whereKey($ids);
            },
        );
    }

    /**
     * Match the current model by its own Scout keys, or by related Searchable hits.
     *
     * @param  class-string<Model>  $modelClass
     * @param  class-string<Model>  $relatedModelClass
     * @param  array<string, int|string>  $wheres
     * @param  array<string, int|string>  $relatedWheres
     */
    public static function makeMatchingSelfOrRelated(
        string $modelClass,
        string $relatedModelClass,
        string $relation,
        string $name = 'q',
        bool $onlyTrashed = false,
        array $wheres = [],
        array $relatedWheres = [],
        bool $relatedHybrid = false,
    ): AllowedFilter {
        return AllowedFilter::callback(
            $name,
            function (Builder $query, mixed $value) use (
                $modelClass,
                $relatedModelClass,
                $relation,
                $onlyTrashed,
                $wheres,
                $relatedWheres,
                $relatedHybrid,
            ): void {
                if (! is_string($value) || mb_trim($value) === '') {
                    return;
                }

                $term = mb_trim($value);
                $selfIds = self::keys($modelClass, $term, $onlyTrashed, $wheres);
                $relatedIds = self::keys($relatedModelClass, $term, false, $relatedWheres, $relatedHybrid);

                $query->where(function (Builder $inner) use ($selfIds, $relatedIds, $relation): void {
                    $inner->whereKey($selfIds)
                        ->orWhereHas($relation, function (Builder $related) use ($relatedIds): void {
                            $related->whereKey($relatedIds);
                        });
                });
            },
        );
    }

    /**
     * @param  class-string<Model>  $modelClass
     * @param  array<string, int|string>  $wheres
     * @return list<int|string>
     */
    public static function keys(
        string $modelClass,
        string $value,
        bool $onlyTrashed = false,
        array $wheres = [],
        bool $hybrid = false,
    ): array {
        $useHybrid = $hybrid && Config::string('scout.driver') === 'database';

        try {
            $keys = self::keysFromSearch($modelClass, $value, $onlyTrashed, $wheres, $useHybrid);
        } catch (Throwable $exception) {
            throw_unless($useHybrid, $exception);

            // Titan can return zero vectors without a provider exception, which
            // 500s the palette. Keyword LIKE still finds IBANs and filenames.
            Log::warning('Scout hybrid search fell back to keyword search.', [
                'model' => $modelClass,
                'exception' => $exception::class,
            ]);

            return self::keysFromSearch($modelClass, $value, $onlyTrashed, $wheres, hybrid: false);
        }

        // Seeded rows often have Embeddings::fake() vectors. A real Titan query
        // then ranks nothing. Retry keyword so filename / OCR still hit.
        if ($useHybrid && $keys === []) {
            Log::warning('Scout hybrid search returned no keys; retrying keyword search.', [
                'model' => $modelClass,
            ]);

            return self::keysFromSearch($modelClass, $value, $onlyTrashed, $wheres, hybrid: false);
        }

        return $keys;
    }

    /**
     * @param  class-string<Model>  $modelClass
     * @param  array<string, int|string>  $wheres
     * @return list<int|string>
     */
    private static function keysFromSearch(
        string $modelClass,
        string $value,
        bool $onlyTrashed,
        array $wheres,
        bool $hybrid,
    ): array {
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

        if ($hybrid) {
            $scoutBuilder->hybrid();
        }

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
