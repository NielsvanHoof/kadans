<?php

declare(strict_types=1);

namespace App\Pipelines\Support;

use App\Pipelines\Contracts\PipelineRunner;
use App\Pipelines\Enums\PipelineType;
use RuntimeException;

/**
 * Owning domains register their runner here (from their own
 * ServiceProvider::boot()) instead of Pipelines hardcoding per-type behaviour.
 */
final class PipelineRunnerRegistry
{
    /** @var array<string, PipelineRunner> */
    private array $runners = [];

    public function register(PipelineRunner $runner): void
    {
        $this->runners[$runner->type()->value] = $runner;
    }

    public function for(PipelineType $type): PipelineRunner
    {
        return $this->runners[$type->value]
            ?? throw new RuntimeException("No runner registered for pipeline type [{$type->value}].");
    }
}
