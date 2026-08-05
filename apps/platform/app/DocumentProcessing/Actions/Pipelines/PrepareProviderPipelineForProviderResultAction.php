<?php

declare(strict_types=1);

namespace App\DocumentProcessing\Actions\Pipelines;

use App\DocumentProcessing\Enums\PipelineStepOperation;
use App\DocumentProcessing\Enums\PipelineStepStatus;
use App\DocumentProcessing\Models\Pipeline;
use App\DocumentProcessing\Models\PipelineStep;

/**
 * Catch up provider pipeline steps so async completion can advance from the provider step.
 * Covers runs where submit never recorded progress.
 */
final readonly class PrepareProviderPipelineForProviderResultAction
{
    public function __construct(
        private PipelineStateAction $pipelineState,
    ) {}

    public function handle(
        Pipeline $pipeline,
        ?string $provider,
        ?string $providerJobId,
        string $providerStepKey,
    ): Pipeline {
        if ($provider !== null || $providerJobId !== null) {
            $pipeline->forceFill([
                'provider' => $provider ?? $pipeline->provider,
                'provider_job_id' => $providerJobId ?? $pipeline->provider_job_id,
            ])->saveQuietly();
        }

        $submit = $this->step($pipeline, 'submit');

        if ($submit instanceof PipelineStep) {
            if ($submit->status === PipelineStepStatus::Pending) {
                $this->pipelineState->handle($pipeline, PipelineStepOperation::Start, 'submit');
                $this->pipelineState->handle($pipeline, PipelineStepOperation::Complete, 'submit');
            } elseif ($submit->status === PipelineStepStatus::Running) {
                $this->pipelineState->handle($pipeline, PipelineStepOperation::Complete, 'submit');
            }
        }

        $providerStep = $this->step($pipeline, $providerStepKey);

        if ($providerStep instanceof PipelineStep && $providerStep->status === PipelineStepStatus::Pending) {
            $this->pipelineState->handle($pipeline, PipelineStepOperation::Start, $providerStepKey);
        }

        return $pipeline->refresh();
    }

    private function step(Pipeline $pipeline, string $key): ?PipelineStep
    {
        return PipelineStep::query()
            ->where('pipeline_id', $pipeline->id)
            ->where('key', $key)
            ->first();
    }
}
