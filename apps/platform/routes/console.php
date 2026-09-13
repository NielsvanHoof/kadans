<?php

declare(strict_types=1);

use App\Dossiers\Console\Commands\Reminders\SendScheduledDossierRemindersCommand;
use App\Pipelines\Console\Commands\MonitorPipelinesCommand;
use App\Pipelines\Console\Commands\RetryDuePipelinesCommand;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schedule;
use Spatie\Health\Commands\DispatchQueueCheckJobsCommand;
use Spatie\Health\Commands\RunHealthChecksCommand;
use Spatie\Health\Commands\ScheduleCheckHeartbeatCommand;

/*
 * Every withoutOverlapping() here passes an explicit expiry, because the
 * default is 1440 — a *day*.
 *
 * The mutex is released in the run's `finally`, and Laravel also releases it on
 * SIGTERM (`$releaseOnTerminationSignals`), so an ordinary run and an ordinary
 * Cloud deploy both clean up. What does not clean up is SIGKILL and the OOM
 * killer, and on a burstable instance shared with Octane and Horizon those are
 * the realistic ways a bulk delete dies. A leftover key then skips the command
 * silently — no exception, no failed job, nothing for ScheduleCheck to notice —
 * for as long as the expiry says.
 *
 * The cost of a too-long expiry is that outage; the cost of a too-short one is
 * two copies of the command overlapping. So each value is that command's
 * realistic worst-case runtime rounded up, never a shared constant, and never
 * longer than its own cadence would tolerate being skipped.
 *
 * ScheduledCommandMutexTest fails if a scheduled command is registered without
 * one.
 */

// onOneServer() like everything else: these write shared state (the heartbeat
// key, the health result rows), so a second scheduler would duplicate rather
// than corroborate.
//
// Seconds of work apiece. Five minutes is already five missed runs.
Schedule::command(DispatchQueueCheckJobsCommand::class)->everyMinute()->onOneServer()->withoutOverlapping(5);
Schedule::command(ScheduleCheckHeartbeatCommand::class)->everyMinute()->onOneServer()->withoutOverlapping(5);
Schedule::command(RunHealthChecksCommand::class)->everyMinute()->onOneServer()->withoutOverlapping(5);

Schedule::command('horizon:snapshot')->everyFiveMinutes()->onOneServer()->withoutOverlapping(5);

Schedule::command(SendScheduledDossierRemindersCommand::class)
    ->hourly()
    ->onOneServer()
    ->withoutOverlapping(30);

// O(tenants): a loop of per-tenant queries every five minutes (.ai/rules/app.md).
// Ten minutes covers a slow pass without letting a dead one mask stuck runs for long.
Schedule::command(MonitorPipelinesCommand::class)
    ->everyFiveMinutes()
    ->onOneServer()
    ->withoutOverlapping(10);

Schedule::command(RetryDuePipelinesCommand::class)
    ->everyFiveMinutes()
    // Leader election matters most here: a second scheduler would re-dispatch
    // the same due retries, not merely duplicate a read.
    ->onOneServer()
    // The most expensive command to lose. Nothing else re-queues a failed run,
    // and a Stuck run is not picked up by this sweep at all, so a stale mutex
    // here is a straight outage of automatic recovery.
    ->withoutOverlapping(10);

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
 *
 * The expiries are wider than the every-minute commands because these chunk
 * through bulk deletes and are the ones most likely to be OOM-killed mid-run,
 * but they stay well inside the 24h gap to the next occurrence.
 */
Schedule::command('queue:prune-failed', [
    '--hours' => Config::integer('retention.failed_jobs_hours'),
])->daily()->at('04:30')->onOneServer()->withoutOverlapping(30);

Schedule::command('queue:prune-batches', [
    '--hours' => Config::integer('retention.job_batches_hours'),
])->daily()->at('04:35')->onOneServer()->withoutOverlapping(30);

Schedule::command('model:prune')->daily()->at('04:40')->onOneServer()->withoutOverlapping(60);

Schedule::command('retention:prune-notifications')
    ->daily()
    ->at('04:45')
    ->onOneServer()
    ->withoutOverlapping(60);

Schedule::command('retention:prune-agent-conversations')
    ->daily()
    ->at('04:50')
    ->onOneServer()
    ->withoutOverlapping(60);

// Hourly, so the expiry must stay under the hour it would otherwise skip.
Schedule::command('retention:prune-dossier-exports')
    ->hourly()
    ->onOneServer()
    ->withoutOverlapping(30);

// Deletes across dossiers and object storage; the longest of the nightly set.
Schedule::command('retention:purge-legal')
    ->daily()
    ->at('05:00')
    ->onOneServer()
    ->withoutOverlapping(90);

// Weekly, and the largest single table, so it gets the widest window.
Schedule::command('retention:prune-activity-log')
    ->weeklyOn(0, '05:30')
    ->onOneServer()
    ->withoutOverlapping(120);

/*
 * Telescope is a dev dependency, is in composer's dont-discover list, and
 * AppServiceProvider only registers its provider when the environment is
 * local. Scheduling the prune unconditionally meant production ran a command
 * that does not exist there, failing every day and muddying the ScheduleCheck
 * signal that is supposed to mean something.
 */
if (App::environment('local')) {
    Schedule::command('telescope:prune')->daily()->at('05:45')->withoutOverlapping(15);
}
