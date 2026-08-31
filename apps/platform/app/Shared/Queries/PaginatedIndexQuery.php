<?php

declare(strict_types=1);

namespace App\Shared\Queries;

use App\Shared\Queries\Filters\ScoutSearchFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedSort;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * Shared Spatie Query Builder + pagination for workspace index lists.
 *
 * Concrete queries declare subject, filters, sorts, row mapping, and toolbar metadata.
 * Call paginate() from handle() so signatures can vary (e.g. Activity needs Tenant).
 * Activity log is the exception: it uses simplePaginate() so the page skip
 * COUNT(*) on the fastest-growing table. Other indexes stay length-aware.
 *
 * @template TModel of Model
 */
abstract class PaginatedIndexQuery
{
    /**
     * Base Eloquent query before Spatie filters/sorts are applied.
     *
     * @return Builder<TModel>
     */
    abstract protected function subject(): Builder;

    /**
     * @return list<AllowedFilter|string>
     */
    abstract protected function allowedFilters(): array;

    /**
     * @return list<AllowedSort|string>
     */
    abstract protected function allowedSorts(): array;

    abstract protected function defaultSort(): AllowedSort|string;

    /**
     * Map a model to the Inertia row shape for this index.
     *
     * @param  TModel  $model
     * @return array<string, mixed>
     */
    abstract protected function mapModel(Model $model): array;

    /**
     * Toolbar config for the shared IndexQuery UI (filters / sorts / defaults).
     *
     * @return array{
     *     filters: list<array<string, mixed>>,
     *     sorts: list<array{key: string, label: string}>,
     *     defaults: array{sort: string}
     * }
     */
    abstract public function toolbarMetadata(): array;

    /**
     * Current request filter/sort values plus toolbar metadata for Inertia.
     *
     * @return array{
     *     filters: mixed,
     *     sort: mixed,
     *     indexQuery: array{
     *         filters: list<array<string, mixed>>,
     *         sorts: list<array{key: string, label: string}>,
     *         defaults: array{sort: string},
     *         search_truncated: bool
     *     }
     * }
     */
    final public function indexQueryProps(): array
    {
        return [
            'filters' => request()->input('filter', []),
            'sort' => request()->query('sort'),
            'indexQuery' => [
                ...$this->toolbarMetadata(),
                // Set during paginate()/simplePaginate() when ScoutSearchFilter
                // hits scout.key_limit — callers must invoke handle() first.
                'search_truncated' => (bool) request()->attributes->get(
                    ScoutSearchFilter::REQUEST_ATTRIBUTE_TRUNCATED,
                    false,
                ),
            ],
        ];
    }

    protected function perPage(): int
    {
        return 15;
    }

    protected function pageName(): string
    {
        return 'page';
    }

    /**
     * Build a Spatie QueryBuilder with allowed filters/sorts applied from the request.
     *
     * @param  Builder<TModel>|null  $subject
     * @return QueryBuilder<TModel>
     */
    protected function queryBuilder(?Builder $subject = null): QueryBuilder
    {
        return QueryBuilder::for($subject ?? $this->subject())
            ->allowedFilters(...$this->allowedFilters())
            ->allowedSorts(...$this->allowedSorts())
            ->defaultSort($this->defaultSort());
    }

    /**
     * Paginate the configured query and map each row for Inertia.
     *
     * @param  Builder<TModel>|null  $subject
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    protected function paginate(?Builder $subject = null, ?string $pageName = null): LengthAwarePaginator
    {
        /** @var LengthAwarePaginator<int, TModel> $paginator */
        $paginator = $this->queryBuilder($subject)
            ->paginate(
                perPage: $this->perPage(),
                pageName: $pageName ?? $this->pageName(),
            )
            ->withQueryString();

        /** @var LengthAwarePaginator<int, array<string, mixed>> */
        return $paginator->through(
            function (Model $model): array {
                /** @var TModel $model */
                return $this->mapModel($model);
            },
        );
    }

    /**
     * Same mapping as paginate(), without COUNT(*) of the filtered set.
     *
     * Use this only when the UI is prev/next (the activity log). Other
     * workspace indexes stay length-aware so numbered page links stay honest.
     *
     * @param  Builder<TModel>|null  $subject
     * @return Paginator<int, array<string, mixed>>
     */
    protected function simplePaginate(?Builder $subject = null, ?string $pageName = null): Paginator
    {
        /** @var Paginator<int, TModel> $paginator */
        $paginator = $this->queryBuilder($subject)
            ->simplePaginate(
                perPage: $this->perPage(),
                pageName: $pageName ?? $this->pageName(),
            )
            ->withQueryString();

        /** @var Paginator<int, array<string, mixed>> */
        return $paginator->through(
            function (Model $model): array {
                /** @var TModel $model */
                return $this->mapModel($model);
            },
        );
    }
}
