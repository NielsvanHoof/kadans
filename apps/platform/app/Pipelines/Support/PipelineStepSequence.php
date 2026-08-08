<?php

declare(strict_types=1);

namespace App\Pipelines\Support;

use App\Pipelines\Enums\PipelineStepKey;
use App\Pipelines\Models\Pipeline;
use App\Pipelines\Models\PipelineStep;
use App\Pipelines\Transitions\PipelineStepTransitions;
use Closure;

/**
 * Records a run of adjacent step transitions inside a lock PipelineState is
 * already holding. Handed to the callback given to PipelineState::run().
 *
 * Only the advancing operations live here. A failure ends a sequence rather
 * than continuing it, and heartbeats deliberately do not broadcast, so both
 * stay on PipelineState as single operations.
 */
final readonly class PipelineStepSequence
{
    /**
     * @param  Closure(Pipeline, PipelineStepKey): PipelineStep  $lockStep
     */
    public function __construct(
        private Pipeline $pipeline,
        private PipelineStepTransitions $stepTransitions,
        private Closure $lockStep,
    ) {}

    public function start(PipelineStepKey $stepKey): self
    {
        $this->stepTransitions->start($this->pipeline, $this->step($stepKey));

        return $this;
    }

    public function complete(PipelineStepKey $stepKey): self
    {
        $this->stepTransitions->complete($this->pipeline, $this->step($stepKey));

        return $this;
    }

    public function skip(PipelineStepKey $stepKey): self
    {
        $this->stepTransitions->skip($this->pipeline, $this->step($stepKey));

        return $this;
    }

    private function step(PipelineStepKey $stepKey): PipelineStep
    {
        return ($this->lockStep)($this->pipeline, $stepKey);
    }
}
