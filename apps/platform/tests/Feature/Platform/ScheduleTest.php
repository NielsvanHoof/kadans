<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;

/**
 * @return list<Event>
 */
function scheduledEvents(): array
{
    return resolve(Schedule::class)->events();
}

function scheduledEventFor(string $artisanCommand): ?Event
{
    foreach (scheduledEvents() as $event) {
        if (str_contains($event->command ?? '', "artisan' ".$artisanCommand)) {
            return $event;
        }
    }

    return null;
}

test('nightly maintenance runs clear of the database backup window', function (string $command, string $expression): void {
    // RDS snapshots daily in 02:00-03:00 UTC and patches Mon 03:15-04:15 UTC
    // (infra/src/shared/database.ts). These are all bulk deletes on a
    // burstable instance, so none of them may land inside those windows.
    $event = scheduledEventFor($command);

    expect($event)->not->toBeNull("[{$command}] is not scheduled.")
        ->and($event?->expression)->toBe($expression);
})->with([
    ['queue:prune-failed', '30 4 * * *'],
    ['queue:prune-batches', '35 4 * * *'],
    ['model:prune', '40 4 * * *'],
    ['retention:prune-notifications', '45 4 * * *'],
    ['retention:prune-agent-conversations', '50 4 * * *'],
    ['retention:purge-legal', '0 5 * * *'],
    ['retention:prune-activity-log', '30 5 * * 0'],
]);

test('horizon snapshot runs every five minutes for dashboard metrics', function (): void {
    $event = scheduledEventFor('horizon:snapshot');

    expect($event)->not->toBeNull()
        ->and($event?->expression)->toBe('*/5 * * * *')
        ->and($event?->onOneServer)->toBeTrue();
});

test('every scheduled command elects a single runner', function (): void {
    foreach (scheduledEvents() as $event) {
        // withoutOverlapping() only stops a command overlapping itself; it does
        // not stop a second scheduler firing the same command in the same
        // minute. pipelines:retry-due would re-dispatch real work if it did.
        expect($event->onOneServer)->toBeTrue(sprintf(
            'Scheduled command [%s] is missing onOneServer().',
            $event->getSummaryForDisplay(),
        ));
    }
});

test('telescope prune is not scheduled outside local', function (): void {
    // Telescope is a dev dependency in composer's dont-discover list and its
    // provider is only registered when the environment is local, so scheduling
    // the prune unconditionally made production fail it nightly and muddied the
    // ScheduleCheck signal. The suite runs as `testing`, which is exactly the
    // not-local case that used to be broken.
    expect(app()->environment('local'))->toBeFalse()
        ->and(scheduledEventFor('telescope:prune'))->toBeNull();
});

test('every scheduled command bounds its overlap mutex', function (): void {
    // Illuminate\Console\Scheduling\ManagesAttributes::$expiresAt. Laravel
    // releases the mutex in the run's `finally` and on SIGTERM, so this only
    // bites on SIGKILL and the OOM killer — and then the command is skipped
    // silently for the whole window, with nothing raised for ScheduleCheck to
    // see. A day of that is never the answer for a command that runs hourly or
    // faster, so the framework default is not an acceptable value here.
    $frameworkDefaultMinutes = 1440;

    foreach (scheduledEvents() as $event) {
        expect($event->withoutOverlapping)->toBeTrue(sprintf(
            'Scheduled command [%s] is missing withoutOverlapping().',
            $event->getSummaryForDisplay(),
        ))->and($event->expiresAt)->toBeLessThan($frameworkDefaultMinutes, sprintf(
            'Scheduled command [%s] takes the default %d-minute overlap mutex. '
            .'Pass an explicit expiry sized to its realistic runtime.',
            $event->getSummaryForDisplay(),
            $frameworkDefaultMinutes,
        ));
    }
});
