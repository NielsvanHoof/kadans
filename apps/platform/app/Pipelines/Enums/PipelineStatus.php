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

    /**
     * Mirrors settledValues() for the inverse set — the runs a human still has
     * to deal with. Used for whereIn() on the base query builder.
     *
     * @return list<string>
     */
    public static function retryableValues(): array
    {
        $values = [];

        foreach (self::cases() as $status) {
            if ($status->isRetryable()) {
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
     * A failed, stuck, or cancelled run may be restarted.
     */
    public function isRetryable(): bool
    {
        return match ($this) {
            self::Failed, self::Stuck, self::Cancelled => true,
            self::Pending, self::Running, self::Completed => false,
        };
    }

    /**
     * Finished and not retryable: the run produced a result.
     */
    public function isSettled(): bool
    {
        return match ($this) {
            self::Completed => true,
            self::Pending, self::Running, self::Failed, self::Stuck, self::Cancelled => false,
        };
    }
}
