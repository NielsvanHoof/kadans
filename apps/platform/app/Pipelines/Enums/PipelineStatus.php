<?php

declare(strict_types=1);

namespace App\Pipelines\Enums;

enum PipelineStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
    case Stuck = 'stuck';
    case Cancelled = 'cancelled';

    /**
     * @return list<string>
     */
    public static function settledValues(): array
    {
        $values = [];

        foreach (self::cases() as $status) {
            if ($status->isSettled()) {
                $values[] = $status->value;
            }
        }

        return $values;
    }

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Completed, self::Failed, self::Stuck, self::Cancelled => true,
            self::Pending, self::Running => false,
        };
    }

    /**
     * A failed or stuck run may be restarted. Completed and cancelled stay closed.
     */
    public function isRetryable(): bool
    {
        return match ($this) {
            self::Failed, self::Stuck => true,
            self::Pending, self::Running, self::Completed, self::Cancelled => false,
        };
    }

    /**
     * Finished and not retryable: the run produced a result or was cancelled.
     */
    public function isSettled(): bool
    {
        return match ($this) {
            self::Completed, self::Cancelled => true,
            self::Pending, self::Running, self::Failed, self::Stuck => false,
        };
    }
}
