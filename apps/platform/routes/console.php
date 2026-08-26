<?php

declare(strict_types=1);

use App\Dossiers\Console\Commands\SendScheduledDossierRemindersCommand;
use App\Pipelines\Console\Commands\MonitorPipelinesCommand;
use App\Pipelines\Console\Commands\RetryDuePipelinesCommand;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schedule;
use Spatie\Health\Commands\DispatchQueueCheckJobsCommand;
use Spatie\Health\Commands\RunHealthChecksCommand;
use Spatie\Health\Commands\ScheduleCheckHeartbeatCommand;

// onOneServer() like everything else: these write shared state (the heartbeat
// key, the health result rows), so a second scheduler would duplicate rather
// than corroborate.
Schedule::command(DispatchQueueCheckJobsCommand::class)->everyMinute()->onOneServer()->withoutOverlapping();
Schedule::command(ScheduleCheckHeartbeatCommand::class)->everyMinute()->onOneServer()->withoutOverlapping();
Schedule::command(RunHealthChecksCommand::class)->everyMinute()->onOneServer()->withoutOverlapping();

Schedule::command('horizon:snapshot')->everyFiveMinutes()->onOneServer()->withoutOverlapping();

Schedule::command(SendScheduledDossierRemindersCommand::class)
    ->hourly()
    ->onOneServer()
    ->withoutOverlapping();

Schedule::command(MonitorPipelinesCommand::class)
    ->everyFiveMinutes()
    ->onOneServer()
    ->withoutOverlapping();

Schedule::command(RetryDuePipelinesCommand::class)
    ->everyFiveMinutes()
    // Leader election matters most here: a second scheduler would re-dispatch
    // the same due retries, not merely duplicate a read.
    ->onOneServer()
    ->withoutOverlapping();

/*
 * Nightly maintenance runs after the database's own windows, not across them.
 *
 * RDS takes its snapshot daily in 02:00-03:00 UTC and patches in
 * Mon 03:15-04:15 UTC (infra/src/shared/database.ts). These are all bulk
 * deletes on a burstable instance, so overlapping them with a snapshot is the
 * worst available combination. They are also staggered rather than fired
 * together, so they compete with each other as little as with the backup.
 *
 * onOneServer() throughout: withoutOverlapping() is a cache mutex that stops a
 * command overlapping *itself*, not a second scheduler running the same minute.
 */
Schedule::command('queue:prune-failed', [
    '--hours' => Config::integer('retention.failed_jobs_hours'),
])->daily()->at('04:30')->onOneServer()->withoutOverlapping();

Schedule::command('queue:prune-batches', [
    '--hours' => Config::integer('retention.job_batches_hours'),
])->daily()->at('04:35')->onOneServer()->withoutOverlapping();

Schedule::command('model:prune')->daily()->at('04:40')->onOneServer()->withoutOverlapping();

Schedule::command('retention:prune-notifications')
    ->daily()
    ->at('04:45')
    ->onOneServer()
    ->withoutOverlapping();

Schedule::command('retention:prune-dossier-exports')
    ->hourly()
    ->onOneServer()
    ->withoutOverlapping();

Schedule::command('retention:purge-legal')
    ->daily()
    ->at('05:00')
    ->onOneServer()
    ->withoutOverlapping();

Schedule::command('activitylog:clean')
    ->weeklyOn(0, '05:30')
    ->onOneServer()
    ->withoutOverlapping();

/*
 * Telescope is a dev dependency, is in composer's dont-discover list, and
 * AppServiceProvider only registers its provider when the environment is
 * local. Scheduling the prune unconditionally meant production ran a command
 * that does not exist there, failing every day and muddying the ScheduleCheck
 * signal that is supposed to mean something.
 */
if (App::environment('local')) {
    Schedule::command('telescope:prune')->daily()->at('05:45')->withoutOverlapping();
}
