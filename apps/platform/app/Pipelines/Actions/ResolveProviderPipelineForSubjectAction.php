<?php

declare(strict_types=1);

namespace App\Pipelines\Actions;

use App\Pipelines\Enums\PipelineStatus;
use App\Pipelines\Enums\PipelineType;
use App\Pipelines\Models\Pipeline;
use Illuminate\Database\Eloquent\Model;

use function is_numeric;
use function is_string;

/**
 * Find a provider pipeline for a pipelineable subject during async completion/failure.
 * Prefers a run already tagged with the provider job id, then the latest open run.
 */
final class ResolveProviderPipelineForSubjectAction
{
    public function handle(
        Model $subject,
        PipelineType $type,
        ?string $providerJobId = null,
    ): ?Pipeline {
        $subjectId = $subject->getKey();

        if (! is_numeric($subjectId)) {
            return null;
        }

        $query = Pipeline::query()
            ->where('pipelineable_type', $subject->getMorphClass())
            ->where('pipelineable_id', (int) $subjectId)
            ->where('type', $type->value);
        $query->getQuery()->orderByDesc('id');

        if (is_string($providerJobId) && $providerJobId !== '') {
            $matchedQuery = clone $query;
            $matchedQuery->getQuery()->where('provider_job_id', $providerJobId);
            $matched = $matchedQuery->first();

            if ($matched instanceof Pipeline) {
                return $matched;
            }
        }

        $openQuery = clone $query;
        $openQuery->getQuery()->whereNotIn('status', PipelineStatus::settledValues());

        return $openQuery->first();
    }
}
