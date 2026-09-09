<?php

declare(strict_types=1);

namespace App\Reporting\Queries\Pipelines;

use App\Pipelines\Contracts\PipelineSubjectPresenter;
use App\Pipelines\Data\PipelineShowData;
use App\Pipelines\Data\PipelineSubjectData;
use App\Pipelines\Models\Pipeline;
use App\Pipelines\Support\PipelineSubjectPresenterRegistry;
use App\Shared\Database\MorphType;
use Illuminate\Database\Eloquent\Model;

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

        $pipeline->load(['steps', 'pipelineable']);

        if ($presenter instanceof PipelineSubjectPresenter && $presenter->eagerLoads() !== []) {
            $pipeline->loadMorph('pipelineable', [
                $presenter->subjectClass() => $presenter->eagerLoads(),
            ]);
        }

        return [
            'pipeline' => PipelineShowData::fromModel(
                $pipeline,
                $this->subjectData($pipeline, $presenter),
            )->toArray(),
        ];
    }

    private function subjectData(Pipeline $pipeline, ?PipelineSubjectPresenter $presenter): PipelineSubjectData
    {
        $subject = $pipeline->pipelineable;
        $describable = $presenter instanceof PipelineSubjectPresenter && $subject instanceof Model;

        return new PipelineSubjectData(
            type: MorphType::classBasename($pipeline->pipelineable_type),
            id: $pipeline->pipelineable_id,
            label: $describable ? $presenter->label($subject) : null,
            context: $describable ? $presenter->context($subject) : null,
            href: $describable ? $presenter->href($subject, $pipeline) : null,
        );
    }
}
