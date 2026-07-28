<?php

declare(strict_types=1);

namespace App\DocumentProcessing\Enums;

enum PipelineStepStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
    case Stuck = 'stuck';
    case Skipped = 'skipped';
}
