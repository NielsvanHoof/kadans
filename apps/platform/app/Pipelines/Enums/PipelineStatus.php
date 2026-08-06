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

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Completed, self::Failed, self::Stuck, self::Cancelled => true,
            self::Pending, self::Running => false,
        };
    }
}
