<?php

declare(strict_types=1);

namespace App\Pipelines\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Config;

/**
 * When, if ever, a failed run should try again on its own.
 *
 * Exponential on attempt_number, capped both in delay and in how many automatic
 * attempts the platform will spend. Past the attempt cap there is no next time:
 * something is failing for a reason a retry will not fix, and the run should
 * stay visible as failed rather than churn.
 */
final readonly class PipelineRetryBackoff
{
    public function shouldRetryAutomatically(int $attemptNumber): bool
    {
        return $attemptNumber < $this->maxAttempts();
    }

    public function nextRetryAt(int $attemptNumber): ?CarbonInterface
    {
        if (! $this->shouldRetryAutomatically($attemptNumber)) {
            return null;
        }

        return CarbonImmutable::now()->addMinutes($this->delayMinutes($attemptNumber));
    }

    public function delayMinutes(int $attemptNumber): int
    {
        $base = max(1, Config::integer('pipelines.retry.base_delay_minutes', 5));
        $multiplier = max(1, Config::integer('pipelines.retry.multiplier', 4));
        $ceiling = max($base, Config::integer('pipelines.retry.max_delay_minutes', 240));

        $exponent = max(0, $attemptNumber - 1);
        $delay = $base * $multiplier ** $exponent;

        return min($delay, $ceiling);
    }

    public function maxAttempts(): int
    {
        return max(1, Config::integer('pipelines.retry.max_attempts', 3));
    }
}
