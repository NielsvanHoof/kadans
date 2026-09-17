<?php

declare(strict_types=1);

use App\Platform\Support\Observability\DebugbarStorageOpen;
use Illuminate\Foundation\DevCommands;
use Illuminate\Http\Request;

test('uses FrankenPHP with bounded worker lifetimes', function (): void {
    expect(config('octane.server'))
        ->toBe('frankenphp')
        ->and(config('octane.max_requests'))
        ->toBe(500)
        ->and(config('octane.max_execution_time'))
        ->toBe(120);
});

test('resets the per-worker Spatie permission collection between requests', function (): void {
    // PermissionRegistrar is a singleton holding every permission in worker memory,
    // and forgetCachedPermissions() only clears the Redis key plus the worker that
    // called it. Without this listener, provisioning a firm leaves every other
    // worker unable to see the new tenant's roles, so the owner's first dashboard
    // visits alternate 200/403 until the workers recycle.
    expect(config('permission.register_octane_reset_listener'))->toBeTrue();
});

test('lets the Sail supervisor own the local HTTP server and Horizon own the queues', function (): void {
    $commandNames = collect(DevCommands::commands())->pluck('name');

    expect($commandNames)
        ->not->toContain('server')
        ->not->toContain('queue')
        ->not->toContain('ocr')
        ->not->toContain('transcription')
        ->not->toContain('dossier-export')
        ->toContain('horizon', 'vite', 'reverb');
});

test('skips AWS result consumers while OCR and transcription stay on mock drivers', function (): void {
    // phpunit/.env.testing keep mock drivers even if Pulumi queue URLs exist locally.
    expect(config('ocr.driver'))
        ->toBe('mock')
        ->and(config('transcription.driver'))
        ->toBe('mock');

    $commandNames = collect(DevCommands::commands())->pluck('name');

    expect($commandNames)
        ->not->toContain('textract-consume')
        ->not->toContain('transcribe-consume');
});

test('skips the malware verdict consumer when no results queue is configured', function (): void {
    /*
     * Gated on the queue alone, unlike the two above — there is no scanning
     * driver to switch. A consumer started against a queue that does not exist
     * fails on every poll, which is noise in `php artisan dev` rather than a
     * signal, and locally the queue only exists after `composer floci:lambdas`.
     */
    expect(config('scanning.results_queue_url'))->toBeEmpty();

    expect(collect(DevCommands::commands())->pluck('name'))
        ->not->toContain('scan-consume');
});

test('keeps Debugbar storage.open safe when Octane boots without a client IP', function (): void {
    // Class string (not a Closure) so config:cache / optimize can serialize it.
    expect(config('debugbar.storage.open'))->toBe(DebugbarStorageOpen::class);

    // FrankenPHP worker boot has an empty REMOTE_ADDR; IpUtils used to throw.
    $bootRequest = Request::create('/', server: ['REMOTE_ADDR' => '']);

    expect($bootRequest->getClientIp())->toBe('')
        ->and(DebugbarStorageOpen::resolve($bootRequest))->toBeFalse()
        ->and(DebugbarStorageOpen::resolve(Request::create('/', server: ['REMOTE_ADDR' => '127.0.0.1'])))
        ->toBeTrue();
});

test('can cache configuration with the Debugbar storage.open resolver', function (): void {
    $this->artisan('config:cache')->assertSuccessful();

    try {
        $cached = require config_path('../bootstrap/cache/config.php');

        expect($cached['debugbar']['storage']['open'] ?? null)
            ->toBe(DebugbarStorageOpen::class);
    } finally {
        $this->artisan('config:clear')->assertSuccessful();
    }
});
