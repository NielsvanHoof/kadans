<?php

declare(strict_types=1);

namespace App\Platform\Queries;

use App\Platform\Data\DataSubjectRequestData;
use App\Platform\Enums\DataSubjectRequestStatus;
use App\Platform\Enums\DataSubjectRequestType;
use App\Platform\Models\DataSubjectRequest;
use App\Shared\Queries\PaginatedIndexQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedSort;

/**
 * The workspace compliance list: open requests first, oldest due date first, so
 * whatever is closest to breaching the one-month deadline is at the top.
 *
 * This used to load every request the tenant had ever had, with three eager
 * loads, on every render. The set only grows — DataSubjectRequest is not
 * Prunable, deliberately, because a compliance record has to outlive the
 * request it describes — so an unpaginated list was a page that got slower
 * every month and never got faster.
 *
 * @extends PaginatedIndexQuery<DataSubjectRequest>
 */
final class FetchDataSubjectRequestIndexQuery extends PaginatedIndexQuery
{
    /**
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function handle(): LengthAwarePaginator
    {
        return $this->paginate();
    }

    /**
     * @return array{
     *     filters: list<array<string, mixed>>,
     *     sorts: list<array{key: string, label: string}>,
     *     defaults: array{sort: string}
     * }
     */
    public function toolbarMetadata(): array
    {
        return [
            'filters' => [
                [
                    'key' => 'status',
                    'type' => 'select',
                    'label' => __('Status'),
                    'options' => array_map(
                        static fn (DataSubjectRequestStatus $status): array => [
                            'value' => $status->value,
                            'label' => $status->label(),
                        ],
                        DataSubjectRequestStatus::cases(),
                    ),
                ],
                [
                    'key' => 'type',
                    'type' => 'select',
                    'label' => __('Type'),
                    'options' => array_map(
                        static fn (DataSubjectRequestType $type): array => [
                            'value' => $type->value,
                            'label' => $type->label(),
                        ],
                        DataSubjectRequestType::cases(),
                    ),
                ],
            ],
            'sorts' => [
                ['key' => 'due_at', 'label' => __('Due date (soonest)')],
                ['key' => '-due_at', 'label' => __('Due date (latest)')],
                ['key' => '-requested_at', 'label' => __('Newest first')],
                ['key' => 'requested_at', 'label' => __('Oldest first')],
            ],
            'defaults' => [
                'sort' => 'due_at',
            ],
        ];
    }

    /**
     * @return Builder<DataSubjectRequest>
     */
    protected function subject(): Builder
    {
        return DataSubjectRequest::with(['client', 'requestedBy', 'completedBy']);
    }

    protected function allowedFilters(): array
    {
        return [
            AllowedFilter::exact('status'),
            AllowedFilter::exact('type'),
        ];
    }

    protected function allowedSorts(): array
    {
        return [
            $this->deadlineSort(),
            AllowedSort::field('requested_at'),
        ];
    }

    protected function defaultSort(): AllowedSort
    {
        return $this->deadlineSort();
    }

    /**
     * @return array<string, mixed>
     */
    protected function mapModel(Model $model): array
    {
        /** @var DataSubjectRequest $model */
        return DataSubjectRequestData::fromModel($model)->toArray();
    }

    /**
     * Open requests first, then by deadline.
     *
     * The two-part ordering is the whole point of this list — whatever is
     * closest to breaching the one-month statutory deadline has to sit at the
     * top of page one, and a closed request can never breach. It is expressed
     * as a sort rather than baked into the subject query so that picking
     * "Newest first" in the toolbar genuinely reorders the list instead of
     * being silently overridden by a leading ORDER BY.
     */
    private function deadlineSort(): AllowedSort
    {
        return AllowedSort::callback(
            'due_at',
            static function (Builder $query, bool $descending): void {
                $query->getQuery()->orderByRaw(
                    'case when status = ? then 0 else 1 end',
                    [DataSubjectRequestStatus::Pending->value],
                );

                $query->getQuery()->orderBy('due_at', $descending ? 'desc' : 'asc');
            },
        );
    }
}
