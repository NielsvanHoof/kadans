<?php

declare(strict_types=1);

namespace App\Shared\Http\Middleware;

use App\Shared\Http\ContentSecurityPolicy;
use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

use function is_string;
use function mb_trim;
use function sprintf;

/**
 * Baseline response hardening for every web route.
 *
 * The Vite nonce is generated on the way in, so the root Blade view and Vite's
 * asset/font directives can reference it; the policy is built on the way out.
 *
 * Headers are only set when absent, so routes with stricter needs can override
 * them first — see HardenClientPortalResponse, which downgrades Referrer-Policy
 * to `no-referrer` for magic-link pages.
 */
final readonly class AddSecurityHeaders
{
    public function __construct(
        private ContentSecurityPolicy $contentSecurityPolicy,
        private Repository $config,
    ) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Always rotate the nonce: the Vite singleton survives between Octane
        // requests, so a stale value must never be reused even when CSP is off.
        $nonce = Vite::useCspNonce();

        $response = $next($request);

        $this->applyBaselineHeaders($response);

        if ($request->isSecure()) {
            $this->applyStrictTransportSecurity($response);
        }

        if ($this->config->get('security.csp.enabled') === true && $this->shouldSendPolicy($response)) {
            $response->headers->set(
                $this->contentSecurityPolicy->headerName(),
                $this->contentSecurityPolicy->build(
                    $nonce,
                    forHorizon: $this->isHorizonDashboard($request),
                ),
            );
        }

        return $response;
    }

    /**
     * Horizon's Vue dashboard needs CSP relaxations the main app must not get.
     */
    private function isHorizonDashboard(Request $request): bool
    {
        $path = mb_trim((string) $this->config->get('horizon.path', 'horizon'), '/');

        if ($path === '') {
            return false;
        }

        return $request->is($path, $path.'/*');
    }

    private function applyBaselineHeaders(Response $response): void
    {
        $referrerPolicy = $this->config->get('security.referrer_policy');
        $permissionsPolicy = $this->config->get('security.permissions_policy');

        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Cross-Origin-Opener-Policy' => 'same-origin',
            'Referrer-Policy' => is_string($referrerPolicy) ? $referrerPolicy : null,
            'Permissions-Policy' => is_string($permissionsPolicy) ? $permissionsPolicy : null,
        ];

        foreach ($headers as $header => $value) {
            if ($value !== null && ! $response->headers->has($header)) {
                $response->headers->set($header, $value);
            }
        }
    }

    private function applyStrictTransportSecurity(Response $response): void
    {
        if ($this->config->get('security.hsts.enabled') !== true) {
            return;
        }

        if ($response->headers->has('Strict-Transport-Security')) {
            return;
        }

        $value = sprintf('max-age=%d', (int) $this->config->get('security.hsts.max_age', 31_536_000));

        if ($this->config->get('security.hsts.include_subdomains') === true) {
            $value .= '; includeSubDomains';
        }

        if ($this->config->get('security.hsts.preload') === true) {
            $value .= '; preload';
        }

        $response->headers->set('Strict-Transport-Security', $value);
    }

    /**
     * Only HTML documents need the policy.
     *
     * Streamed document downloads and previews carry their own, much stricter,
     * sandbox policy set in UploadedDocumentController — do not overwrite it.
     */
    private function shouldSendPolicy(Response $response): bool
    {
        if ($response instanceof StreamedResponse) {
            return false;
        }

        if ($response->headers->has($this->contentSecurityPolicy->headerName())) {
            return false;
        }

        $contentType = $response->headers->get('Content-Type');

        return $contentType === null || Str::startsWith($contentType, 'text/html');
    }
}
