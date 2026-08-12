<?php

declare(strict_types=1);

use App\Shared\Http\ContentSecurityPolicy;
use Illuminate\Foundation\Vite;

use function Pest\Laravel\get;

test('sends the baseline hardening headers on web responses', function (): void {
    $response = get('/login');

    $response->assertOk();
    $response->assertHeader('X-Content-Type-Options', 'nosniff');
    $response->assertHeader('X-Frame-Options', 'DENY');
    $response->assertHeader('Cross-Origin-Opener-Policy', 'same-origin');
    $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');

    expect($response->headers->get('Permissions-Policy'))
        ->toContain('camera=()')
        ->toContain('microphone=()')
        ->toContain('geolocation=()');
});

test('sends a nonce-based content security policy that blocks inline scripts', function (): void {
    $response = get('/login');

    $policy = $response->headers->get('Content-Security-Policy');

    expect($policy)
        ->toContain("object-src 'none'")
        ->toContain("frame-ancestors 'none'")
        ->toContain("base-uri 'self'")
        ->toContain("form-action 'self'")
        // Scripts are nonce-gated; a bare 'unsafe-inline' would defeat the point.
        ->toMatch("/script-src [^;]*'nonce-[A-Za-z0-9+\/=]+'/")
        ->not->toContain("script-src 'self' 'unsafe-inline'");
});

test('emits the nonce from the policy header into the root document', function (): void {
    $response = get('/login');

    $policy = (string) $response->headers->get('Content-Security-Policy');

    preg_match("/'nonce-([A-Za-z0-9+\/=]+)'/", $policy, $matches);

    expect($matches)->toHaveKey(1);

    // The inline appearance script must carry the same nonce or the page breaks.
    $response->assertSee('nonce="'.$matches[1].'"', escape: false);
});

test('nonces every executable script and style in the document', function (): void {
    $response = get('/login');

    preg_match_all('/<(script|style)[^>]*>/i', (string) $response->getContent(), $tags);

    $unnonced = array_values(array_filter(
        $tags[0],
        // Inertia's page payload is type="application/json": a data block, which
        // the HTML spec never executes and therefore never CSP-checks.
        fn (string $tag): bool => ! str_contains($tag, 'nonce=')
            && ! str_contains($tag, 'application/json'),
    ));

    expect($unnonced)
        ->toBeEmpty();
});

test('rotates the nonce between requests', function (): void {
    $first = (string) get('/login')->headers->get('Content-Security-Policy');
    $second = (string) get('/login')->headers->get('Content-Security-Policy');

    expect($first)->not->toBe($second);
});

test('omits strict transport security on plaintext requests', function (): void {
    get('/login')->assertHeaderMissing('Strict-Transport-Security');
});

test('sends strict transport security over https', function (): void {
    config()->set('app.url', 'https://qompose.test');

    $response = get('https://qompose.test/login');

    expect($response->headers->get('Strict-Transport-Security'))
        ->toContain('max-age=31536000')
        ->toContain('includeSubDomains');
});

test('can be switched to report-only without changing the policy', function (): void {
    // Report-Only is a no-op without a sink — browsers require report-uri/report-to.
    config()->set('security.csp.report_only', true);
    config()->set('security.csp.report_uri', 'https://csp.example.test/report');

    $response = get('/login');

    $response->assertHeaderMissing('Content-Security-Policy');
    expect($response->headers->get('Content-Security-Policy-Report-Only'))
        ->toContain("frame-ancestors 'none'")
        ->toContain('report-uri https://csp.example.test/report');
});

test('report-only without a report uri falls back to enforcing', function (): void {
    config()->set('security.csp.report_only', true);
    config()->set('security.csp.report_uri');

    $response = get('/login');

    $response->assertHeaderMissing('Content-Security-Policy-Report-Only');
    expect($response->headers->get('Content-Security-Policy'))
        ->toContain("frame-ancestors 'none'");
});

test('can be disabled entirely while keeping the baseline headers', function (): void {
    config()->set('security.csp.enabled', false);

    $response = get('/login');

    $response->assertHeaderMissing('Content-Security-Policy');
    $response->assertHeader('X-Content-Type-Options', 'nosniff');
});

describe('policy assembly', function (): void {
    test('allows the reverb websocket the browser actually connects to', function (): void {
        config()->set('broadcasting.connections.reverb.options', [
            'host' => 'ws.qompose.test',
            'port' => 443,
            'scheme' => 'https',
        ]);

        expect(resolve(ContentSecurityPolicy::class)->build('abc123'))
            ->toContain('wss://ws.qompose.test:443');
    });

    test('allows the s3 host that signed document urls redirect to', function (): void {
        config()->set('filesystems.disks.s3', [
            'driver' => 's3',
            'bucket' => 'qompose-documents',
            'region' => 'eu-west-1',
        ]);

        $policy = resolve(ContentSecurityPolicy::class)->build('abc123');

        // PDF previews render in an iframe; images in an <img>.
        expect($policy)
            ->toContain('frame-src')
            ->toContain('https://qompose-documents.s3.eu-west-1.amazonaws.com');
    });

    test('prefers an explicit public endpoint over the derived aws host', function (): void {
        config()->set('filesystems.disks.s3', [
            'driver' => 's3',
            'bucket' => 'local',
            'region' => 'eu-west-1',
            'url' => 'http://localhost:4566',
        ]);

        expect(resolve(ContentSecurityPolicy::class)->build('abc123'))
            ->toContain('http://localhost:4566')
            ->not->toContain('amazonaws.com');
    });

    test('allows data uris for images so the two-factor qr code renders', function (): void {
        expect(resolve(ContentSecurityPolicy::class)->build('abc123'))
            ->toMatch('/img-src [^;]*data:/');
    });

    test('allows the vite dev server on every directive it serves when running hot', function (): void {
        $vite = Mockery::mock(Vite::class);
        $vite->shouldReceive('isRunningHot')->andReturnTrue();
        $vite->shouldReceive('asset')->with('')->andReturn('http://localhost:5173/');

        $policy = new ContentSecurityPolicy(app(), config(), $vite)->build('abc123');

        $directives = collect(explode(';', $policy))
            ->mapWithKeys(function (string $directive): array {
                $parts = preg_split('/\s+/', mb_trim($directive), 2);

                return [$parts[0] => $parts[1] ?? ''];
            });

        // font-src is the one that shipped broken: laravel-vite-plugin serves
        // .woff2 from the dev server, not this origin.
        foreach (['script-src', 'style-src', 'img-src', 'font-src', 'connect-src', 'media-src', 'worker-src'] as $directive) {
            expect($directives[$directive])->toContain('http://localhost:5173');
        }

        // The HMR socket runs on the same host over ws://, and only connect-src
        // can ever match a websocket — it has no business in font-src or img-src.
        expect($directives['connect-src'])->toContain('ws://localhost:5173')
            ->and($directives['font-src'])->not->toContain('ws://')
            ->and($directives['img-src'])->not->toContain('ws://');
    });

    test('never leaks the dev server into the policy when not running hot', function (): void {
        expect(resolve(ContentSecurityPolicy::class)->build('abc123'))
            ->not->toContain('localhost:5173');
    });

    test('appends operator-configured extra sources', function (): void {
        config()->set('security.csp.extra_sources', [
            'connect-src' => ['https://o0.ingest.sentry.io'],
        ]);

        expect(resolve(ContentSecurityPolicy::class)->build('abc123'))
            ->toContain('https://o0.ingest.sentry.io');
    });
});
