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
        'database.connections.pgsql.sslmode' => 'verify-full',
        // Any readable file will do: the check is that one is configured and
        // present, not what it contains.
        'database.connections.pgsql.sslrootcert' => base_path('composer.json'),
        'database.redis.cache.scheme' => 'tls',
        'database.redis.default.scheme' => 'tls',
        'database.redis.session.scheme' => 'tls',
        'database.redis.default.database' => '0',
        'database.redis.session.database' => '2',
        'filesystems.default' => 's3',
        'filesystems.disks.s3.report' => true,
        'filesystems.disks.s3.throw' => true,
        'logging.default' => 'stack',
        'logging.channels.stack.channels' => ['stderr_json', 'sentry_logs'],
        'mail.default' => 'ses',
        'mail.from.address' => 'support@example.com',
        'ocr.driver' => 'textract',
        'horizon.allowed_emails' => ['ops@example.com'],
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
        'session.connection' => 'session',
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

test('the committed RDS CA bundle is present for shipped DB_SSLROOTCERT', function (): void {
    // Build-time downloads into storage/ do not survive Laravel Cloud's runtime
    // volume, so verify-full needs this file in the deploy artifact.
    $path = base_path('certs/rds-global-bundle.pem');

    expect($path)->toBeFile()
        ->and(is_readable($path))->toBeTrue()
        ->and((string) file_get_contents($path))->toContain('BEGIN CERTIFICATE');
});

test('production rejects unsafe configuration', function (string $key, mixed $value): void {
    config([$key => $value]);

    expect(fn () => resolve(ProductionConfigurationValidator::class)->validate())
        ->toThrow(LogicException::class, $key);
})->with([
    'debug mode' => ['app.debug', true],
    'non-HTTPS application URL' => ['app.url', 'http://app.example.com'],
    'retry_after at data-subject export timeout' => ['queue.connections.redis.retry_after', GenerateDataSubjectExportJob::TIMEOUT_SECONDS],
    'retry_after below data-subject export timeout' => ['queue.connections.redis.retry_after', 90],
    // `prefer` and `require` both look like TLS and neither verifies the
    // server, which is the whole point once the database is publicly
    // addressable rather than shut inside a VPC.
    'unverified database TLS' => ['database.connections.pgsql.sslmode', 'prefer'],
    'encrypted but unauthenticated database TLS' => ['database.connections.pgsql.sslmode', 'require'],
    'plaintext database connection' => ['database.connections.pgsql.sslmode', 'disable'],
    'missing database root certificate' => ['database.connections.pgsql.sslrootcert', null],
    'empty database root certificate' => ['database.connections.pgsql.sslrootcert', ''],
    // verify-full with a path that is not there fails at connection time,
    // not at boot — a deploy that looks healthy and cannot reach its data.
    'unreadable database root certificate' => ['database.connections.pgsql.sslrootcert', '/nonexistent/rds-ca.pem'],
    'unencrypted Redis cache' => ['database.redis.cache.scheme', 'tcp'],
    'unencrypted Redis connection' => ['database.redis.default.scheme', 'tcp'],
    'unencrypted Redis sessions' => ['database.redis.session.scheme', 'tcp'],
    'non-Redis sessions' => ['session.driver', 'file'],
    'sessions on the default Redis connection' => ['session.connection', 'default'],
    'sessions sharing the default Redis DB' => ['database.redis.session.database', '0'],
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
    'unapproved inference provider for document overview' => ['ai.agents.document_overview.provider', 'openai'],
    'unapproved inference provider for reminder drafting' => ['ai.agents.dossier_reminder.provider', 'openai'],
    'agents config replaced wholesale' => ['ai.agents', 'nonsense'],
    'Anthropic model on an agent slot' => ['ai.agents.document_overview.model', 'eu.anthropic.claude-sonnet-4-20250514-v1:0'],
    'bare Anthropic model id on an agent slot' => ['ai.agents.document_overview.model', 'anthropic.claude-3-5-sonnet-20240620-v1:0'],
    'Anthropic model as the Bedrock provider default' => ['ai.providers.bedrock.models.text.default', 'us.anthropic.claude-sonnet-4-20250514-v1:0'],
    'empty Horizon allow list' => ['horizon.allowed_emails', []],
    'Horizon allow list of blank strings' => ['horizon.allowed_emails', ['', '   ']],
    'Horizon allow list replaced wholesale' => ['horizon.allowed_emails', 'ops@example.com'],
]);

/**
 * The fallback is the hazard, not the agent slots.
 *
 * Every agent names a provider today, so `ai.default` is unreachable and reads as
 * harmless. Blank one `AI_*_PROVIDER` env var and that agent falls through to it —
 * which, before decision-11, meant OCR field values from payslips and identity
 * documents going to whatever the default named, with nothing failing. The
 * validator resolves the same way `ResolvesConfiguredModel` does so the blank slot
 * is checked against the value it would actually use.
 */
test('a blanked agent provider is rejected when the fallback is unapproved', function (mixed $blank): void {
    config([
        'ai.agents.document_overview.provider' => $blank,
        'ai.default' => 'openai',
    ]);

    expect(fn () => resolve(ProductionConfigurationValidator::class)->validate())
        ->toThrow(LogicException::class, 'ai.agents.document_overview.provider');
})->with([
    'null' => [null],
    'empty string' => [''],
    'whitespace' => ['   '],
]);

test('a blanked agent provider passes when the fallback is approved', function (): void {
    config([
        'ai.agents.document_overview.provider' => null,
        'ai.default' => 'bedrock',
    ]);

    resolve(ProductionConfigurationValidator::class)->validate();

    expect(true)->toBeTrue();
});

/**
 * Anthropic on Bedrock is refused at boot, not at inference time.
 *
 * Those models need an account-level use-case submission this account has not
 * made, and until it clears they answer with a 404 whose body carries the real
 * reason. That surfaced as three one-second retries and a stuck OCR pipeline
 * (.ai/rules/ai.md), so the model id is now rejected where the operator can see
 * it. `infra/src/config.ts` withholds the IAM grant for the same reason.
 */
test('every agent falling through to the Bedrock default is caught by the provider default check', function (): void {
    // Nothing names its own Anthropic model, so only resolving the fallback
    // catches this — the same shape as the provider check above.
    config([
        'ai.agents.document_overview.model' => null,
        'ai.providers.bedrock.models.text.default' => 'eu.anthropic.claude-sonnet-4-20250514-v1:0',
    ]);

    expect(fn () => resolve(ProductionConfigurationValidator::class)->validate())
        ->toThrow(LogicException::class, 'ai.providers.bedrock.models.text.default');
});

test('the Nova models we can actually invoke pass validation', function (string $model): void {
    config(['ai.agents.document_overview.model' => $model]);

    resolve(ProductionConfigurationValidator::class)->validate();

    expect(true)->toBeTrue();
})->with([
    'Nova Pro' => ['eu.amazon.nova-pro-v1:0'],
    'Nova Lite' => ['eu.amazon.nova-lite-v1:0'],
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
