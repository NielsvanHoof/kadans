<?php

declare(strict_types=1);

namespace App\Reporting\Queries\Pipelines;

use App\Pipelines\Data\PipelineShowData;
use App\Pipelines\Data\PipelineSubjectData;
use App\Pipelines\Models\Pipeline;
use App\Pipelines\Support\PipelineSubjectPresenterRegistry;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;

final readonly class FetchPipelineShowQuery
{
    public function __construct(
        private PipelineSubjectPresenterRegistry $subjectPresenters,
    ) {}

    /**
     * @return array{pipeline: array<string, mixed>}
     */
    public function handle(Pipeline $pipeline): array
    {
        $presenter = $this->subjectPresenters->for($pipeline->type);

        $pipeline->load([
            'steps',
            'pipelineable' => function (Relation $relation): void {
                if ($relation instanceof MorphTo) {
                    $relation->morphWith($this->subjectPresenters->eagerLoads());
                }
            },
        ]);

        return [
            'pipeline' => PipelineShowData::fromModel(
                $pipeline,
                PipelineSubjectData::fromModel($pipeline, $presenter),
            )->toArray(),
        ];
    }
}
