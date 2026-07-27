<?php

declare(strict_types=1);

test('sentry dsn is disabled for local and testing environments', function () {
    $config = (string) file_get_contents(dirname(__DIR__, 2).'/config/sentry.php');

    // Empty DSN disables the SDK; keep local/testing from reporting even when a DSN is set.
    expect($config)
        ->toContain("in_array(env('APP_ENV', 'production'), ['local', 'testing'], true)")
        ->toContain('? null')
        ->toContain("env('SENTRY_LARAVEL_DSN', env('SENTRY_DSN'))");
});
