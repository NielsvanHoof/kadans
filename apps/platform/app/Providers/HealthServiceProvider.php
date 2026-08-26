<?php

declare(strict_types=1);

namespace App\Providers;

use App\Platform\Services\Health\FailedQueueJobsCheck;
use App\Platform\Services\Health\StuckPipelinesCheck;
use Illuminate\Support\ServiceProvider;
use Spatie\Health\Checks\Checks\DatabaseCheck;
use Spatie\Health\Checks\Checks\HorizonCheck;
use Spatie\Health\Checks\Checks\QueueCheck;
use Spatie\Health\Checks\Checks\RedisCheck;
use Spatie\Health\Checks\Checks\ScheduleCheck;
use Spatie\Health\Facades\Health;

final class HealthServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Health::checks([
            DatabaseCheck::new(),
            RedisCheck::new(),
            QueueCheck::new()->failWhenHealthJobTakesLongerThanMinutes(3),
            // Master supervisor must be up on Cloud; QueueCheck alone can pass
            // while Horizon itself is down if a stray queue:work is still running.
            HorizonCheck::new(),
            ScheduleCheck::new()->heartbeatMaxAgeInMinutes(3),
            // QueueCheck proves a worker is consuming; these two prove the work
            // actually finishes. Without them, exhausted jobs and stalled
            // pipelines leave a document "processing" forever with no alert.
            FailedQueueJobsCheck::new()->within(60),
            StuckPipelinesCheck::new(),
        ]);
    }
}
