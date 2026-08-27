<?php

declare(strict_types=1);

namespace App\Shared\Http;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Vite;

use function array_key_exists;
use function array_unique;
use function array_values;
use function implode;
use function is_array;
use function is_scalar;
use function is_string;
use function mb_rtrim;
use function mb_trim;
use function parse_url;
use function sprintf;
use function str_replace;

/**
 * Assembles the Content-Security-Policy sent with every HTML response.
 *
 * Everything that varies per environment is derived from existing config rather
 * than restated: the Vite nonce, the Vite dev server (when running hot), the
 * Reverb websocket, the S3 host for direct presigned uploads and signed download
 * redirects, and the Sentry ingest host the browser SDK reports to.
 *
 * Two deliberate relaxations, both documented in docs/security.md:
 *
 *  - `style-src` allows 'unsafe-inline'. Tailwind v4 injects <style> elements at
 *    runtime in dev, and React sets inline style attributes throughout (dnd-kit
 *    transforms, React Aria positioning). Inline styles are a far weaker vector
 *    than inline scripts, which stay nonce-gated.
 *  - `script-src` is 'self' + nonce rather than 'strict-dynamic'. Every bundle is
 *    same-origin under /build, uploads never land on this origin (they go to S3,
 *    and .js/.html are not accepted file types), so 'self' costs little here and
 *    keeps Vite's dev module graph working without special cases.
 *
 * Local dashboards are a third relaxation, applied only to their own HTML.
 * Horizon and Telescope both need `'unsafe-eval'` (Vue runtime compiler) and
 * `fonts.bunny.net` for Figtree. Never enable those on the main app.
 */
final readonly class ContentSecurityPolicy
{
    public function __construct(
        private Application $application,
        private Repository $config,
        private Vite $vite,
    ) {}

    /**
     * The header name to send under, honouring the report-only toggle.
     *
     * Report-Only without a reporting endpoint is a no-op: browsers refuse to
     * act on it and log a console warning. Fall back to the enforcing header
     * unless `report_uri` is actually set.
     */
    public function headerName(): string
    {
        return $this->wantsReportOnly()
            ? 'Content-Security-Policy-Report-Only'
            : 'Content-Security-Policy';
    }

    /**
     * Build the policy string for this request.
     *
     * Pass `$forHorizon` or `$forTelescope` only for those dashboards — both
     * compile Vue templates via `new Function` and load Figtree from Bunny.
     */
    public function build(?string $nonce, bool $forHorizon = false, bool $forTelescope = false): string
    {
        $scriptSources = ["'self'"];

        if ($nonce !== null && $nonce !== '') {
            $scriptSources[] = sprintf("'nonce-%s'", $nonce);
        }

        $connectSources = ["'self'"];
        $frameSources = ["'self'"];
        $imageSources = ["'self'", 'data:', 'blob:'];

        // Reverb: the browser opens this socket directly from app.tsx.
        foreach ($this->websocketOrigins() as $origin) {
            $connectSources[] = $origin;
        }

        // Sentry: the browser SDK POSTs envelopes to the ingest host from the page.
        $errorReportingHost = $this->browserErrorReportingOrigin();

        if ($errorReportingHost !== null) {
            $connectSources[] = $errorReportingHost;
        }

        // Direct presigned PUT uploads and download/preview redirects hit the storage host.
        $documentHost = $this->documentStorageOrigin();

        if ($documentHost !== null) {
            $connectSources[] = $documentHost;
            $frameSources[] = $documentHost;
            $imageSources[] = $documentHost;
        }

        $directives = [
            'default-src' => ["'self'"],
            'base-uri' => ["'self'"],
            'script-src' => $scriptSources,
            // Inline style *attributes* from React components; see class docblock.
            'style-src' => ["'self'", "'unsafe-inline'"],
            'style-src-attr' => ["'unsafe-inline'"],
            'img-src' => $imageSources,
            'font-src' => ["'self'", 'data:'],
            'connect-src' => $connectSources,
            'frame-src' => $frameSources,
            'media-src' => ["'self'"],
            'worker-src' => ["'self'", 'blob:'],
            'manifest-src' => ["'self'"],
            'object-src' => ["'none'"],
            'form-action' => ["'self'"],
            'frame-ancestors' => ["'none'"],
        ];

        if ($forHorizon || $forTelescope) {
            // Vue runtime compiler + Figtree stylesheet (Horizon and Telescope).
            $directives['script-src'][] = "'unsafe-eval'";
            $directives['style-src'][] = 'https://fonts.bunny.net';
            $directives['font-src'][] = 'https://fonts.bunny.net';
        }

        $directives = $this->applyDevelopmentServerOrigins($directives);
        $directives = $this->applyExtraSources($directives);

        $policy = [];

        foreach ($directives as $directive => $sources) {
            $policy[] = $directive.' '.implode(' ', array_values(array_unique($sources)));
        }

        // Only meaningful once the app is actually served over TLS.
        if (! $this->application->environment('local', 'testing')) {
            $policy[] = 'upgrade-insecure-requests';
        }

        $reportUri = $this->config->get('security.csp.report_uri');

        if (is_string($reportUri) && mb_trim($reportUri) !== '') {
            $policy[] = 'report-uri '.mb_trim($reportUri);
        }

        return implode('; ', $policy);
    }

    /**
     * Whether we should emit Report-Only (needs both the toggle and a sink).
     */
    private function wantsReportOnly(): bool
    {
        if ($this->config->get('security.csp.report_only') !== true) {
            return false;
        }

        $reportUri = $this->config->get('security.csp.report_uri');

        return is_string($reportUri) && mb_trim($reportUri) !== '';
    }

    /**
     * Allow the Vite dev server on every directive it can legitimately serve.
     *
     * When running hot, Vite serves the module graph, the HMR socket, injected
     * styles, and — via laravel-vite-plugin's font middleware — the actual .woff2
     * files from http://localhost:5173 rather than this origin. Listing the
     * directives here instead of at each source array is what stops the next
     * dev-only asset kind from silently getting blocked.
     *
     * @param  array<string, list<string>>  $directives
     * @return array<string, list<string>>
     */
    private function applyDevelopmentServerOrigins(array $directives): array
    {
        $origin = $this->developmentServerOrigin();

        if ($origin === null) {
            return $directives;
        }

        $fetchDirectives = [
            'script-src',
            'style-src',
            'img-src',
            'font-src',
            'connect-src',
            'media-src',
            'worker-src',
        ];

        foreach ($fetchDirectives as $directive) {
            $directives[$directive][] = $origin;
        }

        // HMR runs on the same host over ws(s); only connect-src can match it.
        $directives['connect-src'][] = str_replace(
            ['https://', 'http://'],
            ['wss://', 'ws://'],
            $origin,
        );

        return $directives;
    }

    /**
     * Merge operator-configured origins into the built directives.
     *
     * @param  array<string, list<string>>  $directives
     * @return array<string, list<string>>
     */
    private function applyExtraSources(array $directives): array
    {
        $extra = $this->config->get('security.csp.extra_sources');

        if (! is_array($extra)) {
            return $directives;
        }

        foreach ($extra as $directive => $sources) {
            if (! is_string($directive)) {
                continue;
            }
            if (! is_array($sources)) {
                continue;
            }
            foreach ($sources as $source) {
                if (is_string($source) && $source !== '') {
                    $directives[$directive][] = $source;
                }
            }
        }

        return $directives;
    }

    /**
     * The Vite dev server origin when running hot, otherwise null.
     */
    private function developmentServerOrigin(): ?string
    {
        if (! $this->vite->isRunningHot()) {
            return null;
        }

        // In hot mode asset('') resolves to the dev server URL from the hot file,
        // so we do not have to locate or parse that file ourselves.
        $origin = mb_rtrim(mb_trim($this->vite->asset('')), '/');

        return $origin === '' ? null : $origin;
    }

    /**
     * The Reverb websocket origin the browser connects to.
     *
     * @return list<string>
     */
    private function websocketOrigins(): array
    {
        $options = $this->config->get('broadcasting.connections.reverb.options');

        if (! is_array($options)) {
            return [];
        }

        $host = $options['host'] ?? null;

        if (! is_string($host) || $host === '') {
            return [];
        }

        $scheme = ($options['scheme'] ?? 'https') === 'https' ? 'wss' : 'ws';
        $port = $options['port'] ?? null;
        $authority = $host;

        if (is_scalar($port) && (string) $port !== '') {
            $authority .= ':'.$port;
        }

        return [$scheme.'://'.$authority];
    }

    /**
     * Origin the browser Sentry SDK posts envelopes to, or null when the bundle
     * was built without a DSN (local, testing, and any deploy that opted out).
     *
     * Derived from the DSN rather than restated in `security.csp.extra_sources`
     * for the same reason as the Reverb and S3 origins above: moving the
     * frontend to a different Sentry project would otherwise leave the policy
     * behind, and a blocked envelope POST is invisible — the SDK reports the
     * failure to the endpoint it cannot reach.
     *
     * `originFromUrl` drops the DSN's public key along with the rest of the
     * userinfo, so only `scheme://host` reaches the header.
     */
    private function browserErrorReportingOrigin(): ?string
    {
        $dsn = $this->config->get('services.sentry.browser_dsn');

        if (! is_string($dsn) || mb_trim($dsn) === '') {
            return null;
        }

        return $this->originFromUrl(mb_trim($dsn));
    }

    /**
     * Origin of the S3(-compatible) host that signed document URLs point at.
     *
     * Returns null on the local disk, where documents stream through the app.
     */
    private function documentStorageOrigin(): ?string
    {
        $disk = $this->config->get('filesystems.disks.s3');

        if (! is_array($disk)) {
            return null;
        }

        // A public URL or custom endpoint (Floci) wins — that is what the browser hits.
        foreach (['url', 'endpoint'] as $key) {
            if (! array_key_exists($key, $disk)) {
                continue;
            }
            if (! is_string($disk[$key])) {
                continue;
            }
            if ($disk[$key] === '') {
                continue;
            }
            $origin = $this->originFromUrl($disk[$key]);

            if ($origin !== null) {
                return $origin;
            }
        }

        $bucket = $disk['bucket'] ?? null;
        $region = $disk['region'] ?? null;

        if (! is_string($bucket) || $bucket === '' || ! is_string($region) || $region === '') {
            return null;
        }

        // Virtual-hosted-style endpoint the AWS SDK signs against by default.
        return sprintf('https://%s.s3.%s.amazonaws.com', $bucket, $region);
    }

    /**
     * Reduce a URL to scheme://host[:port], or null if it has no host.
     */
    private function originFromUrl(string $url): ?string
    {
        $parts = parse_url($url);

        if (! is_array($parts)) {
            return null;
        }

        $host = $parts['host'] ?? null;

        if (! is_string($host) || $host === '') {
            return null;
        }

        $origin = ($parts['scheme'] ?? 'https').'://'.$host;

        if (isset($parts['port'])) {
            $origin .= ':'.$parts['port'];
        }

        return $origin;
    }
}
