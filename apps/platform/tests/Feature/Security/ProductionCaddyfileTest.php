<?php

declare(strict_types=1);

/**
 * Edge config regression: Caddy's bare `header Name value` replaces the field
 * on the way out. Referrer-Policy must stay application-owned so portal
 * HardenClientPortalResponse can keep `no-referrer`.
 *
 * Deployed Caddyfiles went away with the container bake (TASK-43.5). The
 * remaining Sail Caddyfile is still an Octane edge, so it must not reclaim
 * the header either.
 */
test('the Sail Caddyfile does not replace the application Referrer-Policy', function (): void {
    $caddyfile = (string) file_get_contents(base_path('docker/8.5/Caddyfile'));

    // Match a directive that sets Referrer-Policy (bare or deferred ?), with
    // or without quotes — any of these would reclaim ownership from PHP.
    expect($caddyfile)
        ->toContain('frankenphp')
        ->not->toMatch('/(?m)^\s*\??Referrer-Policy\b/');
});
