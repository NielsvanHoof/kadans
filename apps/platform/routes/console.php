<?php

declare(strict_types=1);

use App\Dossiers\Console\Commands\SendScheduledDossierRemindersCommand;
use App\Pipelines\Console\Commands\MonitorPipelinesCommand;
use App\Pipelines\Console\Commands\RetryDuePipelinesCommand;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schedule;
use Spatie\Health\Commands\DispatchQueueCheckJobsCommand;
use Spatie\Health\Commands\RunHealthChecksCommand;
use Spatie\Health\Commands\ScheduleCheckHeartbeatCommand;

Schedule::command(DispatchQueueCheckJobsCommand::class)->everyMinute()->withoutOverlapping();
Schedule::command(ScheduleCheckHeartbeatCommand::class)->everyMinute()->withoutOverlapping();
Schedule::command(RunHealthChecksCommand::class)->everyMinute()->withoutOverlapping();

Schedule::command(SendScheduledDossierRemindersCommand::class)
    ->hourly()
    ->onOneServer()
    ->withoutOverlapping();

Schedule::command(MonitorPipelinesCommand::class)
    ->everyFiveMinutes()
    ->withoutOverlapping();

Schedule::command(RetryDuePipelinesCommand::class)
    ->everyFiveMinutes()
    ->withoutOverlapping();

Schedule::command('queue:prune-failed', [
    '--hours' => Config::integer('retention.failed_jobs_hours'),
])->daily()->at('02:00')->withoutOverlapping();

Schedule::command('queue:prune-batches', [
    '--hours' => Config::integer('retention.job_batches_hours'),
])->daily()->at('02:00')->withoutOverlapping();

Schedule::command('model:prune')->daily()->at('02:00')->withoutOverlapping();

Schedule::command('retention:prune-notifications')
    ->daily()
    ->at('02:00')
    ->withoutOverlapping();

Schedule::command('retention:prune-dossier-exports')
    ->hourly()
    ->withoutOverlapping();

Schedule::command('retention:purge-legal')
    ->daily()
    ->at('02:30')
    ->withoutOverlapping();

Schedule::command('activitylog:clean')
    ->weeklyOn(0, '03:00')
    ->withoutOverlapping();

Schedule::command('telescope:prune')->daily();
