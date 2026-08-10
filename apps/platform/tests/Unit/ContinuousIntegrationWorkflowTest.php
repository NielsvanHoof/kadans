<?php

declare(strict_types=1);

test('the complete quality gate includes the production frontend build', function (): void {
    /** @var array{scripts: array<string, list<string>>} $composer */
    $composer = json_decode(
        (string) file_get_contents(dirname(__DIR__, 2).'/composer.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    // Frontend build lives on ci:quality; ci:check composes that plus the full Pest suite.
    expect($composer['scripts']['ci:quality'])
        ->toContain('npm run build')
        ->and($composer['scripts']['ci:check'])
        ->toContain('@ci:quality');
});

test('committed shards.json powers time-balanced CI sharding', function (): void {
    $shardsPath = dirname(__DIR__, 2).'/tests/.pest/shards.json';

    expect(file_exists($shardsPath))->toBeTrue();

    /** @var array{timings: array<string, float>} $shards */
    $shards = json_decode(
        (string) file_get_contents($shardsPath),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($shards['timings'])
        ->toBeArray()
        ->not->toBeEmpty();
});

test('the TypeScript check generates ignored Wayfinder modules on a fresh checkout', function (): void {
    /** @var array{scripts: array<string, string>} $package */
    $package = json_decode(
        (string) file_get_contents(dirname(__DIR__, 2).'/package.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($package['scripts']['wayfinder:generate'])
        ->toBe('php artisan wayfinder:generate --with-form --no-interaction')
        ->and($package['scripts']['questionnaire:generate'])
        ->toBe('php artisan questionnaire:generate-item-types --no-interaction')
        ->and($package['scripts']['types:check'])
        ->toStartWith('npm run wayfinder:generate && npm run questionnaire:generate && ');
});

test('the committed CI env avoids Sail Redis so Artisan generators can run on GitHub Actions', function (): void {
    $envCiPath = dirname(__DIR__, 2).'/.env.ci';

    expect(file_exists($envCiPath))->toBeTrue();

    $envCi = (string) file_get_contents($envCiPath);

    // These must stay array/sync/collection so typescript:transform and friends
    // never try to resolve Sail hostnames (redis, pgsql, mailpit) on CI runners.
    expect($envCi)
        ->toContain('CACHE_STORE=array')
        ->toContain('SESSION_DRIVER=array')
        ->toContain('QUEUE_CONNECTION=sync')
        ->toContain('MAIL_MAILER=array')
        ->toContain('SCOUT_DRIVER=collection')
        ->toContain('OCR_DRIVER=mock')
        // Match production-shaped HSTS so SecurityHeadersTest can assert the
        // HTTPS header without depending on .env / .env.ci alone.
        ->toContain('SECURITY_HSTS_ENABLED=true')
        ->not->toContain('REDIS_HOST=redis')
        ->not->toContain('DB_HOST=pgsql');
});
