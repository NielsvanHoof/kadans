<?php

declare(strict_types=1);

namespace App\Pipelines\Actions;

use App\Pipelines\Models\Pipeline;
use Illuminate\Database\Eloquent\Model;

final class DeletePipelinesForSubjectsAction
{
    /**
     * @param  iterable<Model>  $subjects
     */
    public function handle(iterable $subjects): int
    {
        $subjectIdsByType = [];

        foreach ($subjects as $subject) {
            $subjectId = $subject->getKey();

            if (! is_numeric($subjectId)) {
                continue;
            }

            $subjectIdsByType[$subject->getMorphClass()][] = (int) $subjectId;
        }

        $deleted = 0;

        foreach ($subjectIdsByType as $type => $subjectIds) {
            $pipelineQuery = Pipeline::query();
            $pipelineQuery->getQuery()
                ->where('pipelineable_type', $type)
                ->whereIn('pipelineable_id', array_values(array_unique($subjectIds)));

            $deleted += $pipelineQuery->delete();
        }

        return $deleted;
    }
}
