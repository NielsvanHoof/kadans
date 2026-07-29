<?php

declare(strict_types=1);

use Illuminate\Foundation\DevCommands;
use Illuminate\Http\Request;

it('uses FrankenPHP with bounded worker lifetimes', function (): void {
    expect(config('octane.server'))
        ->toBe('frankenphp')
        ->and(config('octane.max_requests'))
        ->toBe(500)
        ->and(config('octane.max_execution_time'))
        ->toBe(120);
});

it('lets the Sail supervisor own the local HTTP server', function (): void {
    $commandNames = collect(DevCommands::commands())->pluck('name');

    expect($commandNames)
        ->not->toContain('server')
        ->toContain('queue', 'vite', 'reverb');
});

it('keeps Debugbar storage.open safe when Octane boots without a client IP', function (): void {
    $open = config('debugbar.storage.open');

    expect($open)->toBeCallable();

    // FrankenPHP worker boot has an empty REMOTE_ADDR; IpUtils used to throw.
    $bootRequest = Request::create('/', server: ['REMOTE_ADDR' => '']);

    expect($bootRequest->getClientIp())->toBe('')
        ->and(($open)($bootRequest))->toBeFalse()
        ->and($open(Request::create('/', server: ['REMOTE_ADDR' => '127.0.0.1'])))
        ->toBeTrue();
});
