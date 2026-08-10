<?php

declare(strict_types=1);

use App\Platform\Services\Production\ProductionConfigurationValidator;

beforeEach(function (): void {
    $this->app->instance('env', 'production');

    config([
        'app.debug' => false,
        'app.url' => 'https://app.example.com',
        'broadcasting.default' => 'reverb',
        'cache.default' => 'redis',
        'database.redis.cache.scheme' => 'tls',
        'database.redis.default.scheme' => 'tls',
        'filesystems.default' => 's3',
        'filesystems.disks.s3.report' => true,
        'filesystems.disks.s3.throw' => true,
        'logging.default' => 'stack',
        'logging.channels.stack.channels' => ['stderr_json', 'sentry_logs'],
        'mail.default' => 'ses',
        'mail.from.address' => 'support@example.com',
        'ocr.driver' => 'textract',
        'scout.driver' => 'meilisearch',
        'security.two_factor.enforcement' => 'all',
        'sentry.dsn' => 'https://public@sentry.example.com/1',
        'transcription.driver' => 'transcribe',
        'production.allowed_hosts' => ['app.example.com'],
        'production.trusted_proxies' => '10.0.0.0/8',
        'queue.default' => 'redis',
        'reverb.apps.apps.0.allowed_origins' => ['app.example.com'],
        'reverb.apps.apps.0.key' => 'production-reverb-key',
        'reverb.apps.apps.0.options.scheme' => 'https',
        'reverb.apps.apps.0.options.useTLS' => true,
        'reverb.apps.apps.0.secret' => 'production-reverb-secret',
        'session.driver' => 'redis',
        'session.domain' => 'app.example.com',
        'session.encrypt' => true,
        'session.secure' => true,
    ]);
});

test('safe production configuration passes validation', function (): void {
    resolve(ProductionConfigurationValidator::class)->validate();

    expect(true)->toBeTrue();
});

test('production rejects unsafe configuration', function (string $key, mixed $value): void {
    config([$key => $value]);

    expect(fn () => resolve(ProductionConfigurationValidator::class)->validate())
        ->toThrow(LogicException::class, $key);
})->with([
    'debug mode' => ['app.debug', true],
    'non-HTTPS application URL' => ['app.url', 'http://app.example.com'],
    'non-Redis cache' => ['cache.default', 'file'],
    'non-Redis queue' => ['queue.default', 'sync'],
    'unencrypted Redis cache' => ['database.redis.cache.scheme', 'tcp'],
    'unencrypted Redis connection' => ['database.redis.default.scheme', 'tcp'],
    'non-Redis sessions' => ['session.driver', 'file'],
    'unencrypted sessions' => ['session.encrypt', false],
    'insecure session cookie' => ['session.secure', false],
    'wrong session domain' => ['session.domain', 'example.com'],
    'local document storage' => ['filesystems.default', 'local'],
    'suppressed storage exceptions' => ['filesystems.disks.s3.throw', false],
    'unreported storage exceptions' => ['filesystems.disks.s3.report', false],
    'non-Reverb broadcasting' => ['broadcasting.default', 'log'],
    'mock OCR' => ['ocr.driver', 'mock'],
    'mock transcription' => ['transcription.driver', 'mock'],
    'in-memory search driver' => ['scout.driver', 'collection'],
    'disabled search driver' => ['scout.driver', 'null'],
    'missing Sentry DSN' => ['sentry.dsn', null],
    'empty Sentry DSN' => ['sentry.dsn', ''],
    'whitespace Sentry DSN' => ['sentry.dsn', '   '],
    'file-only log stack' => ['logging.channels.stack.channels', ['single']],
    'file-and-rotating-file log stack' => ['logging.channels.stack.channels', ['single', 'daily']],
    'empty log stack' => ['logging.channels.stack.channels', []],
    'missing log channel' => ['logging.default', null],
    'cross-site session cookie' => ['session.same_site', 'none'],
    'unset session same-site' => ['session.same_site', null],
    'optional two-factor authentication' => ['security.two_factor.enforcement', 'off'],
    'unrecognised two-factor mode' => ['security.two_factor.enforcement', 'sometimes'],
    'unset two-factor mode' => ['security.two_factor.enforcement', null],
    'non-delivering mailer' => ['mail.default', 'log'],
    'invalid sender address' => ['mail.from.address', 'invalid'],
    'missing trusted proxies' => ['production.trusted_proxies', null],
    'empty trusted proxies' => ['production.trusted_proxies', ''],
    'REMOTE_ADDR trusted proxies' => ['production.trusted_proxies', 'REMOTE_ADDR'],
    'catch-all trusted proxies' => ['production.trusted_proxies', '*'],
    'missing allowed hosts' => ['production.allowed_hosts', []],
    'allowed hosts matching any IPv4 address' => ['production.allowed_hosts', ['^app\.example\.com$', '^\d{1,3}(?:\.\d{1,3}){3}$']],
    'allowed hosts matching any host' => ['production.allowed_hosts', ['.*']],
    'development Reverb app key' => ['reverb.apps.apps.0.key', 'local-reverb-key'],
    'missing Reverb app key' => ['reverb.apps.apps.0.key', null],
    'development Reverb app secret' => ['reverb.apps.apps.0.secret', 'local-reverb-secret'],
    'missing Reverb app secret' => ['reverb.apps.apps.0.secret', null],
    'non-HTTPS Reverb scheme' => ['reverb.apps.apps.0.options.scheme', 'http'],
    'Reverb TLS disabled' => ['reverb.apps.apps.0.options.useTLS', false],
    'wildcard Reverb allowed origins' => ['reverb.apps.apps.0.allowed_origins', ['*']],
    'empty Reverb allowed origins' => ['reverb.apps.apps.0.allowed_origins', []],
]);

/**
 * The log check asks whether anything leaves the machine, not that every
 * channel does — a file channel alongside one that ships is fine, and a single
 * non-stack channel needs no stack at all.
 *
 * @param  array<string, mixed>  $logging
 */
test('a log stack passes when any channel ships off the host', function (array $logging): void {
    config($logging);

    resolve(ProductionConfigurationValidator::class)->validate();

    expect(true)->toBeTrue();
})->with([
    'structured stderr only' => [['logging.channels.stack.channels' => ['stderr_json']]],
    'file alongside Sentry' => [['logging.channels.stack.channels' => ['single', 'sentry_logs']]],
    'a single non-stack channel' => [['logging.default' => 'stderr_json']],
]);

test('non-production environments may use local services', function (): void {
    $this->app->instance('env', 'testing');

    config([
        'app.debug' => true,
        'app.url' => 'http://localhost',
        'filesystems.default' => 'local',
        'logging.channels.stack.channels' => ['single'],
        'ocr.driver' => 'mock',
        'queue.default' => 'sync',
        'scout.driver' => 'collection',
        'sentry.dsn' => null,
        'transcription.driver' => 'mock',
    ]);

    resolve(ProductionConfigurationValidator::class)->validate();

    expect(true)->toBeTrue();
});

test('composer package discovery can run before production configuration exists', function (): void {
    config(['app.url' => 'http://localhost']);
    $originalArguments = $_SERVER['argv'] ?? null;
    $_SERVER['argv'] = ['artisan', 'package:discover', '--ansi'];

    try {
        resolve(ProductionConfigurationValidator::class)->validate();
    } finally {
        if ($originalArguments === null) {
            unset($_SERVER['argv']);
        } else {
            $_SERVER['argv'] = $originalArguments;
        }
    }

    expect(true)->toBeTrue();
});
