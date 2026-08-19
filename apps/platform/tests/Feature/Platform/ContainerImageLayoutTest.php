<?php

declare(strict_types=1);

/**
 * The deployed image is not exercised by the test suite, so these guard the
 * parts of it that silently stop being true: the per-environment file layout,
 * the role set ECS dispatches on, and the queues a worker actually drains.
 */
test('each deployed environment owns a full container config set', function (string $environment): void {
    foreach (['Dockerfile', 'Caddyfile', 'php.ini', 'entrypoint.sh'] as $file) {
        expect(base_path("docker/{$environment}/{$file}"))->toBeFile();
    }

    // Both start from the shared base, so the PHP version and extension set
    // cannot drift between staging and production.
    expect((string) file_get_contents(base_path("docker/{$environment}/Dockerfile")))
        ->toContain('FROM base');
})->with(['production', 'staging']);

test('every deployed entrypoint dispatches the full role set', function (string $environment): void {
    $entrypoint = (string) file_get_contents(base_path("docker/{$environment}/entrypoint.sh"));

    foreach (['web', 'worker', 'reverb', 'schedule', 'migrate'] as $role) {
        expect($entrypoint)->toContain($role);
    }

    // A container whose config is broken must still be inspectable, so the
    // passthrough branch cannot boot the app first.
    expect($entrypoint)
        ->toContain('exec "$@"')
        ->and($entrypoint)->toContain("docker/{$environment}/Caddyfile");
})->with(['production', 'staging']);

test('the worker role drains every queue a job dispatches onto', function (string $environment): void {
    $entrypoint = (string) file_get_contents(base_path("docker/{$environment}/entrypoint.sh"));

    // .ai/rules/jobs.md: production workers listen to the default queue plus
    // each dedicated one. Read the queue names off the jobs themselves rather
    // than hard-coding them, so a new job on a new queue fails here instead of
    // sitting undrained in Redis.
    $dedicatedQueues = collect(glob(app_path('*/Jobs/*.php')) ?: [])
        ->flatMap(function (string $job): array {
            preg_match_all("/->onQueue\('([^']+)'\)/", (string) file_get_contents($job), $matches);

            return $matches[1];
        })
        ->unique()
        ->values();

    expect($dedicatedQueues)->not->toBeEmpty();

    foreach ($dedicatedQueues->push('default') as $queue) {
        expect($entrypoint)->toContain($queue);
    }
})->with(['production', 'staging']);

test('staging keeps production hardening while carrying an inert Xdebug', function (): void {
    $ini = (string) file_get_contents(base_path('docker/staging/php.ini'));

    expect($ini)
        ->toContain('xdebug.mode=off')
        ->toContain('display_errors=Off')
        ->toContain('expose_php=Off')
        ->toContain('session.cookie_secure=1')
        ->toContain('session.cookie_httponly=1');
});

test('upload limits stay in step across both deployed php.ini files', function (string $environment): void {
    $ini = (string) file_get_contents(base_path("docker/{$environment}/php.ini"));

    // DocumentUploadLimits: 20 MB per file, up to 10 files, plus form overhead.
    expect($ini)
        ->toContain('upload_max_filesize=20M')
        ->toContain('post_max_size=210M');
})->with(['production', 'staging']);

test('the build context cannot carry host AWS credentials into an image layer', function (): void {
    // env/profiles/aws.env holds real IAM keys. It is gitignored, but .dockerignore
    // only excluded .env and .env.*, so `COPY . .` used to bake it into the image.
    $ignored = (string) file_get_contents(base_path('.dockerignore'));

    $entries = collect(explode("\n", $ignored))
        ->map(fn (string $line): string => mb_trim($line))
        ->reject(fn (string $line): bool => $line === '' || str_starts_with($line, '#'));

    expect($entries)->toContain('env')
        ->and(base_path('env/profiles'))->toBeDirectory();
});

/*
 * Anything Vite reads is inlined into the bundle at build time and cannot be
 * changed by redeploying, so every one of them has to arrive as a build argument.
 *
 * This exists because VITE_SENTRY_DSN did not. The image built without it, the
 * bundle shipped with browser error reporting silently off, and the container
 * then failed boot on `services.sentry.browser_dsn` — which config/services.php
 * reads from the very same variable. A missing build arg is invisible until it is
 * expensive, so the check is mechanical rather than remembered.
 */
test('every bundle variable the frontend reads arrives as a build argument', function (): void {
    $root = dirname(__DIR__, 3);

    $sources = '';
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root.'/resources/js', FilesystemIterator::SKIP_DOTS),
    );

    foreach ($files as $file) {
        if ($file->isFile() && in_array($file->getExtension(), ['ts', 'tsx'], true)) {
            $sources .= (string) file_get_contents($file->getPathname());
        }
    }

    preg_match_all('/import\.meta\.env\.(VITE_[A-Z0-9_]+)/', $sources, $matches);

    $required = array_values(array_unique($matches[1]));

    expect($required)->not->toBeEmpty();

    $dockerfile = (string) file_get_contents($root.'/docker/base/Dockerfile');
    $bake = (string) file_get_contents($root.'/docker-bake.hcl');

    // VITE_APP_NAME is baked from a literal in vite.config.ts rather than the
    // environment, and the traces sample rate has a safe compiled default.
    $exempt = ['VITE_APP_NAME', 'VITE_SENTRY_TRACES_SAMPLE_RATE'];

    foreach (array_diff($required, $exempt) as $variable) {
        // REVERB_APP_KEY reaches the build under the REVERB_PUBLIC_KEY arg name,
        // because the value is public and the name should say so.
        $argument = $variable === 'VITE_REVERB_APP_KEY' ? 'REVERB_PUBLIC_KEY' : $variable;

        expect($dockerfile)
            ->toContain('ARG '.$argument)
            ->and($dockerfile)->toContain($variable.'="$'.$argument.'"')
            ->and($bake)->toContain($argument);
    }
});
