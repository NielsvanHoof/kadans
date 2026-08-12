<?php

declare(strict_types=1);

use App\Platform\Support\DebugbarStorageOpen;
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

test('lets the Sail supervisor own the local HTTP server', function (): void {
    $commandNames = collect(DevCommands::commands())->pluck('name');

    expect($commandNames)
        ->not->toContain('server')
        ->toContain('queue', 'ocr', 'transcription', 'dossier-export', 'vite', 'reverb');
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
