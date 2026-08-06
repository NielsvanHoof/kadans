<?php

declare(strict_types=1);

namespace App\Reporting\Queries;

use App\DocumentProcessing\Data\PipelineIndexRowData;
use App\DocumentProcessing\Enums\PipelineStatus;
use App\DocumentProcessing\Enums\PipelineType;
use App\DocumentProcessing\Models\Pipeline;
use App\Shared\Queries\PaginatedIndexQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedSort;

/**
 * @extends PaginatedIndexQuery<Pipeline>
 */
final class FetchPipelineIndexQuery extends PaginatedIndexQuery
{
    /**
     * @return LengthAwarePaginator<int, array{id: int, type: string, status: string, current_stage: string|null, attempt_number: int, last_activity_at: string|null, created_at: string, subject_type: string, subject_id: int|null}>
     */
    public function handle(): LengthAwarePaginator
    {
        /** @var LengthAwarePaginator<int, array{id: int, type: string, status: string, current_stage: string|null, attempt_number: int, last_activity_at: string|null, created_at: string, subject_type: string, subject_id: int|null}> */
        return $this->paginate();
    }

    /**
     * @return array{filters: list<array<string, mixed>>, sorts: list<array{key: string, label: string}>, defaults: array{sort: string}}
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
                        static fn (PipelineStatus $status): array => [
                            'value' => $status->value,
                            'label' => __(str_replace('_', ' ', ucfirst($status->value))),
                        ],
                        PipelineStatus::cases(),
                    ),
                ],
                [
                    'key' => 'type',
                    'type' => 'select',
                    'label' => __('Process'),
                    'options' => array_map(
                        static fn (PipelineType $type): array => [
                            'value' => $type->value,
                            'label' => $type->label(),
                        ],
                        PipelineType::cases(),
                    ),
                ],
                ['key' => 'current_stage', 'type' => 'search', 'label' => __('Current step')],
            ],
            'sorts' => [
                ['key' => '-created_at', 'label' => __('Newest first')],
                ['key' => 'created_at', 'label' => __('Oldest first')],
                ['key' => '-last_activity_at', 'label' => __('Recently active')],
                ['key' => 'status', 'label' => __('Status (A–Z)')],
                ['key' => 'type', 'label' => __('Process (A–Z)')],
            ],
            'defaults' => [
                'sort' => '-created_at',
            ],
        ];
    }

    /** @return Builder<Pipeline> */
    protected function subject(): Builder
    {
        $query = Pipeline::query();
        $query->getQuery()->select([
            'id',
            'type',
            'status',
            'current_stage',
            'attempt_number',
            'last_activity_at',
            'created_at',
            'pipelineable_type',
            'pipelineable_id',
        ]);

        return $query;
    }

    protected function allowedFilters(): array
    {
        return [
            AllowedFilter::exact('type'),
            AllowedFilter::exact('status'),
            AllowedFilter::exact('current_stage'),
        ];
    }

    protected function allowedSorts(): array
    {
        return [
            AllowedSort::field('type'),
            AllowedSort::field('status'),
            AllowedSort::field('created_at'),
            AllowedSort::field('last_activity_at'),
        ];
    }

    protected function defaultSort(): string
    {
        return '-created_at';
    }

    /** @return array<string, mixed> */
    protected function mapModel(Model $model): array
    {
        /** @var Pipeline $model */
        return PipelineIndexRowData::fromModel($model)->toArray();
    }
}
