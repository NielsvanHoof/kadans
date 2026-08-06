<?php

declare(strict_types=1);

namespace App\Pipelines\Enums;

enum PipelineStepOperation: string
{
    case Start = 'start';
    case Complete = 'complete';
    case Fail = 'fail';
    case Skip = 'skip';
    case Stuck = 'stuck';
    case Heartbeat = 'heartbeat';
}
