<?php

declare(strict_types=1);

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;

test('production allowed_hosts never matches a spoofed Host header', function (): void {
    $this->app->instance('env', 'production');

    config([
        'app.url' => 'https://app.example.com',
        'production.allowed_hosts' => ['^'.preg_quote('app.example.com').'$'],
    ]);

    $kernel = $this->app->make(Kernel::class);

    try {
        $spoofed = $kernel->handle(Request::create('http://evil.attacker.test/forgot-password', 'GET'));
        $legitimate = $kernel->handle(Request::create('https://app.example.com/forgot-password', 'GET'));

        expect($spoofed->getStatusCode())->toBe(400)
            ->and($legitimate->getStatusCode())->toBe(200);
    } finally {
        // TrustHosts stores validated patterns on a Symfony base-class static
        // property, outside the per-test application container — it leaks
        // into every later test's Host header unless cleared here.
        Request::setTrustedHosts([]);
    }
});
