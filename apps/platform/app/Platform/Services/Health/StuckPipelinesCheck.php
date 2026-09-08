<?php

declare(strict_types=1);

namespace App\Platform\Services\Health;

use App\Pipelines\Enums\PipelineStatus;
use App\Pipelines\Models\Pipeline;
use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;

/**
 * Surface pipelines that stopped making progress.
 *
 * `pipelines:monitor` already detects and marks these every five minutes, but
 * it only prints to console output, which nothing reads in a container. This
 * turns the same state into an alert.
 *
 * Deliberately unscoped: this is an operator-facing check across the whole
 * installation, so it reads every tenant's rows through the greppable
 * queryWithoutTenantScope() escape hatch rather than running per tenant.
 */
final class StuckPipelinesCheck extends Check
{
    private int $failWhenMoreThan = 0;

    public function failWhenMoreThan(int $count): self
    {
        $this->failWhenMoreThan = $count;

        return $this;
    }

    public function run(): Result
    {
        // toBase() rather than count() on the Eloquent builder: PHPStan's strict
        // rules reject the forwarded static call, as MonitorPipelinesCommand notes.
        $stuck = Pipeline::queryWithoutTenantScope()
            ->where('status', PipelineStatus::Stuck->value)
            ->toBase()
            ->count();

        $result = Result::make()
            ->meta(['stuck_pipelines' => $stuck])
            ->shortSummary((string) $stuck);

        if ($stuck > $this->failWhenMoreThan) {
            return $result->failed(':stuck_pipelines pipeline(s) are stuck and need attention.');
        }

        return $result->ok('No stuck pipelines.');
    }
}
