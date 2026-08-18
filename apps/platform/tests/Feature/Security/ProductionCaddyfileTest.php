<?php

declare(strict_types=1);

/**
 * Edge config regression: Caddy's bare `header Name value` replaces the field
 * on the way out. Referrer-Policy must stay application-owned so portal
 * HardenClientPortalResponse can keep `no-referrer`.
 *
 * Staging is checked alongside production: it serves the same portal to the
 * same kind of client, so the edge must not reclaim the header there either.
 */
test('deployed Caddyfiles do not replace the application Referrer-Policy', function (string $environment): void {
    $caddyfile = (string) file_get_contents(base_path("docker/{$environment}/Caddyfile"));

    // Match a directive that sets Referrer-Policy (bare or deferred ?), with
    // or without quotes — any of these would reclaim ownership from PHP.
    expect($caddyfile)
        ->not->toMatch('/(?m)^\s*\??Referrer-Policy\b/')
        ->and($caddyfile)->toContain('X-Content-Type-Options nosniff')
        ->and($caddyfile)->toContain('-Server');
})->with(['production', 'staging']);
