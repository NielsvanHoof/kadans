<?php

declare(strict_types=1);

namespace App\Reporting\Queries\Pipelines;

use App\Pipelines\Data\PipelineIndexRowData;
use App\Pipelines\Data\PipelineSubjectData;
use App\Pipelines\Enums\PipelineStatus;
use App\Pipelines\Enums\PipelineType;
use App\Pipelines\Models\Pipeline;
use App\Pipelines\Support\PipelineSubjectPresenterRegistry;
use App\Shared\Queries\PaginatedIndexQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedSort;

/**
 * @extends PaginatedIndexQuery<Pipeline>
 */
final class FetchPipelineIndexQuery extends PaginatedIndexQuery
{
    public function __construct(
        private readonly PipelineSubjectPresenterRegistry $subjectPresenters,
    ) {}

    /**
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function handle(): LengthAwarePaginator
    {
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
                            'label' => __(Str::headline($status->value)),
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
        $query = Pipeline::query()->with([
            'pipelineable' => function (Relation $relation): void {
                if ($relation instanceof MorphTo) {
                    $relation->morphWith($this->subjectPresenters->eagerLoads());
                }
            },
        ]);
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
        return PipelineIndexRowData::fromModel(
            $model,
            PipelineSubjectData::fromModel($model, $this->subjectPresenters->for($model->type)),
        )->toArray();
    }
}
