<?php

declare(strict_types=1);

namespace App\Enums;

enum PipelineStepStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
    case Stuck = 'stuck';
    case Skipped = 'skipped';
}
