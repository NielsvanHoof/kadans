<?php

declare(strict_types=1);

namespace App\Pipelines\Actions;

use App\Pipelines\Enums\PipelineStepKey;
use App\Pipelines\Enums\PipelineStepStatus;
use App\Pipelines\Models\Pipeline;
use App\Pipelines\Models\PipelineStep;
use App\Pipelines\Support\PipelineState;
use App\Pipelines\Support\PipelineStepSequence;

use function in_array;

/**
 * Catch up provider pipeline steps so async completion can advance from the provider step.
 * Covers runs where submit never recorded progress.
 */
final readonly class PrepareProviderPipelineForProviderResultAction
{
    public function __construct(
        private PipelineState $pipelineState,
    ) {}

    public function handle(
        Pipeline $pipeline,
        ?string $provider,
        ?string $providerJobId,
        PipelineStepKey $providerStepKey,
    ): Pipeline {
        if ($provider !== null || $providerJobId !== null) {
            $pipeline->forceFill([
                'provider' => $provider ?? $pipeline->provider,
                'provider_job_id' => $providerJobId ?? $pipeline->provider_job_id,
            ])->saveQuietly();
        }

        $submit = $this->step($pipeline, PipelineStepKey::Submit);
        // Safe to read before the submit catch-up below: completing a step moves
        // the pipeline's status and current_stage, never another step's status.
        $providerStep = $this->step($pipeline, $providerStepKey);

        $catchUpSubmit = $submit instanceof PipelineStep
            && in_array($submit->status, [PipelineStepStatus::Pending, PipelineStepStatus::Running], true);
        $startProvider = $providerStep instanceof PipelineStep
            && $providerStep->status === PipelineStepStatus::Pending;

        // Nothing to catch up means no write and no broadcast, as before.
        if (! $catchUpSubmit && ! $startProvider) {
            return $pipeline->refresh();
        }

        $this->pipelineState->run($pipeline, function (PipelineStepSequence $steps) use (
            $submit,
            $catchUpSubmit,
            $startProvider,
            $providerStepKey,
        ): void {
            if ($catchUpSubmit) {
                if ($submit?->status === PipelineStepStatus::Pending) {
                    $steps->start(PipelineStepKey::Submit);
                }

                $steps->complete(PipelineStepKey::Submit);
            }

            if ($startProvider) {
                $steps->start($providerStepKey);
            }
        });

        return $pipeline->refresh();
    }

    private function step(Pipeline $pipeline, PipelineStepKey $key): ?PipelineStep
    {
        return PipelineStep::query()
            ->where('pipeline_id', $pipeline->id)
            ->where('key', $key->value)
            ->first();
    }
}
