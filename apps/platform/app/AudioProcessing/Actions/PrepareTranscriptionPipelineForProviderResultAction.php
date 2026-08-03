<?php

declare(strict_types=1);

namespace App\AudioProcessing\Actions;

use App\DocumentProcessing\Actions\Pipelines\PipelineStateAction;
use App\DocumentProcessing\Enums\PipelineStepOperation;
use App\DocumentProcessing\Enums\PipelineStepStatus;
use App\DocumentProcessing\Models\Pipeline;
use App\DocumentProcessing\Models\PipelineStep;

/**
 * Catch up audio pipeline steps so Transcribe completion can advance from transcribe.
 */
final readonly class PrepareTranscriptionPipelineForProviderResultAction
{
    public function __construct(
        private PipelineStateAction $pipelineState,
    ) {}

    public function handle(Pipeline $pipeline, ?string $provider, ?string $providerJobId): Pipeline
    {
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

        $transcribe = $this->step($pipeline, 'transcribe');

        if ($transcribe instanceof PipelineStep && $transcribe->status === PipelineStepStatus::Pending) {
            $this->pipelineState->handle($pipeline, PipelineStepOperation::Start, 'transcribe');
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
