<?php

declare(strict_types=1);

namespace App\Pipelines\Support;

use App\Pipelines\Contracts\PipelineRetryRunner;
use App\Pipelines\Enums\PipelineType;
use RuntimeException;

/**
 * Owning domains register their retry runner here (from their own
 * ServiceProvider::boot()) instead of Pipelines hardcoding per-type behaviour.
 */
final class PipelineRetryRunnerRegistry
{
    /** @var array<string, PipelineRetryRunner> */
    private array $runners = [];

    public function register(PipelineRetryRunner $runner): void
    {
        $this->runners[$runner->type()->value] = $runner;
    }

    public function for(PipelineType $type): PipelineRetryRunner
    {
        return $this->runners[$type->value]
            ?? throw new RuntimeException("No retry runner registered for pipeline type [{$type->value}].");
    }
}
