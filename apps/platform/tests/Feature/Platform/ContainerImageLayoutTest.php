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
