<?php

declare(strict_types=1);

use App\DocumentProcessing\Jobs\ProcessUploadedDocumentJob;
use App\Dossiers\Jobs\GenerateDossierExportJob;
use App\Platform\Jobs\GenerateDataSubjectExportJob;
use App\Platform\Services\Production\ProductionConfigurationValidator;

covers(ProductionConfigurationValidator::class);

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
        'security.malware_scanning.enabled' => true,
        'security.two_factor.enforcement' => 'all',
        'sentry.dsn' => 'https://public@sentry.example.com/1',
        'services.sentry.browser_dsn' => 'https://public@o1.ingest.sentry.example.com/2',
        'transcription.driver' => 'transcribe',
        'transcription.timeouts.job' => 120,
        'production.allowed_hosts' => ['app.example.com'],
        'production.trusted_proxies' => '10.0.0.0/8',
        'queue.default' => 'redis',
        'queue.connections.redis.retry_after' => 660,
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

    expect(config('queue.connections.redis.retry_after'))
        ->toBeGreaterThan(GenerateDataSubjectExportJob::TIMEOUT_SECONDS)
        ->toBeGreaterThan(GenerateDossierExportJob::TIMEOUT_SECONDS)
        ->toBeGreaterThan(ProcessUploadedDocumentJob::TIMEOUT_SECONDS)
        ->toBeGreaterThan((int) config('transcription.timeouts.job'));
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
    'retry_after at data-subject export timeout' => ['queue.connections.redis.retry_after', GenerateDataSubjectExportJob::TIMEOUT_SECONDS],
    'retry_after below data-subject export timeout' => ['queue.connections.redis.retry_after', 90],
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
    'missing browser Sentry DSN' => ['services.sentry.browser_dsn', null],
    'empty browser Sentry DSN' => ['services.sentry.browser_dsn', ''],
    'whitespace browser Sentry DSN' => ['services.sentry.browser_dsn', '   '],
    'file-only log stack' => ['logging.channels.stack.channels', ['single']],
    'file-and-rotating-file log stack' => ['logging.channels.stack.channels', ['single', 'daily']],
    'empty log stack' => ['logging.channels.stack.channels', []],
    'missing log channel' => ['logging.default', null],
    'cross-site session cookie' => ['session.same_site', 'none'],
    'unset session same-site' => ['session.same_site', null],
    // The Laravel session expires on idleness too, so a portal idle window at or
    // above session.lifetime promises a window the session store drops first.
    'portal idle window at the session lifetime' => ['portal.session_idle_minutes', 120],
    'portal idle window beyond the session lifetime' => ['portal.session_idle_minutes', 240],
    'unset portal idle window' => ['portal.session_idle_minutes', null],
    // Off is the safe *local* default (no GuardDuty attached to a fake bucket), so
    // it is exactly the value that must never reach a shipped environment.
    'disabled malware scanning' => ['security.malware_scanning.enabled', false],
    'unset malware scanning' => ['security.malware_scanning.enabled', null],
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

/**
 * Schemes and hosts are case-insensitive, so a technically-correct APP_URL must
 * not fail boot. A prefix match on 'https://' used to reject the first case.
 */
test('a valid application URL is accepted whatever its casing', function (string $url, string $domain): void {
    config(['app.url' => $url, 'session.domain' => $domain]);

    resolve(ProductionConfigurationValidator::class)->validate();

    expect(true)->toBeTrue();
})->with([
    'uppercase scheme' => ['HTTPS://app.example.com', 'app.example.com'],
    'mixed-case host' => ['https://App.Example.com', 'app.example.com'],
    'mixed-case session domain' => ['https://app.example.com', 'App.Example.com'],
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

test('staging is held to the same configuration as production', function (): void {
    // Staging ships the same image and serves the same kind of data, so it must
    // not be able to boot with development services (.ai/rules/config.md).
    $this->app->instance('env', 'staging');

    config(['filesystems.default' => 'local']);

    expect(fn () => resolve(ProductionConfigurationValidator::class)->validate())
        ->toThrow(LogicException::class, 'filesystems.default');
});

test('production rejects retry_after at or below the transcription job timeout', function (): void {
    config([
        'queue.connections.redis.retry_after' => 180,
        'transcription.timeouts.job' => 180,
    ]);

    expect(fn () => resolve(ProductionConfigurationValidator::class)->validate())
        ->toThrow(LogicException::class, 'queue.connections.redis.retry_after');
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
