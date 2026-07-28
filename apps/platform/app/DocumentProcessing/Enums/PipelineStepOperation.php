<?php

declare(strict_types=1);

namespace App\DocumentProcessing\Enums;

enum PipelineStepOperation: string
{
    case Start = 'start';
    case Complete = 'complete';
    case Fail = 'fail';
    case Skip = 'skip';
    case Stuck = 'stuck';
    case Heartbeat = 'heartbeat';
}
