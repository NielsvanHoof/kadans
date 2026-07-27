<?php

declare(strict_types=1);

use Illuminate\Foundation\DevCommands;

it('uses FrankenPHP with bounded worker lifetimes', function () {
    expect(config('octane.server'))
        ->toBe('frankenphp')
        ->and(config('octane.max_requests'))
        ->toBe(500)
        ->and(config('octane.max_execution_time'))
        ->toBe(120);
});

it('lets the Sail supervisor own the local HTTP server', function () {
    $commandNames = collect(DevCommands::commands())->pluck('name');

    expect($commandNames)
        ->not->toContain('server')
        ->toContain('queue', 'vite', 'reverb');
});
