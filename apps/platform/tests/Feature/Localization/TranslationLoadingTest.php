<?php

declare(strict_types=1);

use App\Accounts\Actions\Localization\LoadFrontendTranslationsAction;
use App\Accounts\Enums\Locale;
use App\Accounts\Exceptions\MissingTranslationsException;
use App\Platform\Services\Production\ProductionConfigurationValidator;
use App\Shared\Support\Localization\TranslationDomains;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Lang;
use Illuminate\Translation\FileLoader;

covers(LoadFrontendTranslationsAction::class);

test('frontend dictionary equals the Laravel translator dictionary for every locale', function (string $locale): void {
    $frontend = resolve(LoadFrontendTranslationsAction::class)->handle($locale);
    $backend = Lang::getLoader()->load($locale, '*', '*');

    ksort($frontend, SORT_STRING);
    ksort($backend, SORT_STRING);

    expect($frontend)->toBe($backend)
        ->and(count($frontend))->toBe(1501);
})->with(Locale::values());

test('a missing translation file throws MissingTranslationsException', function (): void {
    $path = app_path('Dossiers/'.TranslationDomains::RelativeDirectory.'/en.json');
    $original = File::get($path);

    try {
        File::put($path, '');

        // Construct directly — the container singleton may already hold a
        // memoized dictionary from an earlier test in this process.
        expect(fn () => (new LoadFrontendTranslationsAction)->handle('en'))
            ->toThrow(MissingTranslationsException::class, $path);
    } finally {
        File::put($path, $original);
    }
});

test('the loader registers each domain exactly once across resolutions', function (): void {
    $first = resolve('translation.loader');
    $second = resolve('translation.loader');

    expect($first)->toBeInstanceOf(FileLoader::class)
        ->and($second)->toBeInstanceOf(FileLoader::class);

    /** @var FileLoader $first */
    /** @var FileLoader $second */
    $jsonPaths = $first->jsonPaths();
    $counts = array_count_values($jsonPaths);

    foreach (TranslationDomains::NAMES as $domain) {
        $path = app_path("{$domain}/".TranslationDomains::RelativeDirectory);

        expect($counts[$path] ?? 0)
            ->toBe(1, "Domain [{$domain}] is not registered exactly once.");
    }

    // Pins the Octane no-growth property: resolving again must not append paths.
    expect(count($second->jsonPaths()))->toBe(count($jsonPaths));
});

test('a shipped environment fails to boot when copy is missing', function (): void {
    $path = app_path('Shared/'.TranslationDomains::RelativeDirectory.'/nl.json');
    $original = File::get($path);

    $this->app->instance('env', 'production');

    config([
        'app.debug' => false,
        'app.url' => 'https://app.example.com',
        'broadcasting.default' => 'reverb',
        'database.connections.pgsql.sslmode' => 'verify-full',
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
        'logging.channels.stack.channels' => ['stderr_json'],
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
        'production.allowed_hosts' => ['app.example.com'],
        'production.trusted_proxies' => '10.0.0.0/8',
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

    try {
        File::delete($path);

        expect(fn () => resolve(ProductionConfigurationValidator::class)->validate())
            ->toThrow(LogicException::class, TranslationDomains::relativePath('Shared', 'nl'));
    } finally {
        File::put($path, $original);
    }
});
