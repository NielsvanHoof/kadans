<?php

declare(strict_types=1);

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;

test('client IP is taken from X-Forwarded-For when the peer is a trusted proxy', function (): void {
    // Mimic an ALB in the VPC CIDR forwarding the real client address.
    TrustProxies::at('10.0.0.0/8');

    $kernel = $this->app->make(Kernel::class);
    $request = Request::create(
        'https://app.example.com/up',
        server: [
            'REMOTE_ADDR' => '10.0.1.50',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.99',
        ],
    );

    try {
        $kernel->handle($request);

        expect($request->ip())->toBe('203.0.113.99');
    } finally {
        // TrustProxies writes Symfony Request static state that leaks across
        // tests unless cleared — same pattern as TrustedHostsTest.
        Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_AWS_ELB);
        TrustProxies::flushState();
    }
});

test('X-Forwarded-For is ignored when the peer is outside the trusted proxy range', function (): void {
    TrustProxies::at('10.0.0.0/8');

    $kernel = $this->app->make(Kernel::class);
    $request = Request::create(
        'https://app.example.com/up',
        server: [
            // Direct public peer — spoofed XFF must not become the client IP.
            'REMOTE_ADDR' => '198.51.100.10',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.99',
        ],
    );

    try {
        $kernel->handle($request);

        expect($request->ip())->toBe('198.51.100.10');
    } finally {
        Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_AWS_ELB);
        TrustProxies::flushState();
    }
});
