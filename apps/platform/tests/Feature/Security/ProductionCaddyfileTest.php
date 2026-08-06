<?php

declare(strict_types=1);

/**
 * Edge config regression: Caddy's bare `header Name value` replaces the field
 * on the way out. Referrer-Policy must stay application-owned so portal
 * HardenClientPortalResponse can keep `no-referrer`.
 */
test('production Caddyfile does not replace application Referrer-Policy', function (): void {
    $caddyfile = (string) file_get_contents(base_path('docker/production/Caddyfile'));

    // Match a directive that sets Referrer-Policy (bare or deferred ?), with
    // or without quotes — any of these would reclaim ownership from PHP.
    expect($caddyfile)
        ->not->toMatch('/(?m)^\s*\??Referrer-Policy\b/')
        ->and($caddyfile)->toContain('X-Content-Type-Options nosniff')
        ->and($caddyfile)->toContain('-Server');
});
