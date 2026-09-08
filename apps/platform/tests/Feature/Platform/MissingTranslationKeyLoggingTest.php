<?php

declare(strict_types=1);

use App\Platform\Services\Health\FailedQueueJobsCheck;
use App\Platform\Services\Health\StuckPipelinesCheck;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Log;

/**
 * @return list<string>
 */
function captureTranslationWarnings(callable $callback): array
{
    $warnings = [];
    Log::listen(function (MessageLogged $message) use (&$warnings): void {
        if ($message->level === 'warning' && str_starts_with($message->message, 'Missing translation key')) {
            $warnings[] = $message->message;
        }
    });

    $callback();

    return $warnings;
}

test('does not log empty translation keys', function (): void {
    $warnings = captureTranslationWarnings(function (): void {
        app()->setLocale('nl');
        __('');
    });

    expect($warnings)->toBeEmpty();
});

test('still logs a missing product sentence', function (): void {
    $warnings = captureTranslationWarnings(function (): void {
        app()->setLocale('nl');
        __('A definitely missing product sentence.');
    });

    expect($warnings)->toContain(
        'Missing translation key [A definitely missing product sentence.] for locale [nl].',
    );
});

test('health check notification copy is not logged as missing in Dutch', function (): void {
    $warnings = captureTranslationWarnings(function (): void {
        app()->setLocale('nl');
        FailedQueueJobsCheck::new()->within(60)->run()->getNotificationMessage();
        StuckPipelinesCheck::new()->run()->getNotificationMessage();
        __('');
    });

    expect($warnings)->toBeEmpty();
});
