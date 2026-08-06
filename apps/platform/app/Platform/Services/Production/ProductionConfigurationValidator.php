<?php

declare(strict_types=1);

namespace App\Platform\Services\Production;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Foundation\Application;
use LogicException;

use function in_array;
use function is_array;
use function is_string;
use function parse_url;
use function sprintf;
use function str_starts_with;

final readonly class ProductionConfigurationValidator
{
    /**
     * Hosts an `allowed_hosts` pattern must never match. Catches overly
     * broad regressions (e.g. a pattern matching any IPv4 address) that
     * would let a spoofed Host header pass trusted-host validation.
     *
     * @var list<string>
     */
    private const array UNTRUSTED_CANARY_HOSTS = [
        '169.254.169.254',
        '203.0.113.10',
        'evil.attacker.example',
    ];

    /**
     * Checked-in development Reverb credentials (.env / .env.example). A
     * known secret lets an attacker self-sign a private-channel subscribe
     * for any origin, bypassing routes/channels.php entirely.
     */
    private const string DEV_REVERB_APP_KEY = 'local-reverb-key';

    private const string DEV_REVERB_APP_SECRET = 'local-reverb-secret';

    public function __construct(
        private Application $application,
        private Repository $config,
    ) {}

    public function validate(): void
    {
        if (! $this->application->isProduction() || $this->isPackageDiscovery()) {
            return;
        }

        $invalidKeys = array_values(array_filter([
            $this->config->get('app.debug') === false ? null : 'app.debug',
            $this->isHttpsUrl() ? null : 'app.url',
            $this->matches('cache.default', 'redis'),
            $this->matches('queue.default', 'redis'),
            $this->matches('database.redis.default.scheme', 'tls'),
            $this->matches('database.redis.cache.scheme', 'tls'),
            $this->matches('session.driver', 'redis'),
            $this->config->get('session.encrypt') === true ? null : 'session.encrypt',
            $this->config->get('session.secure') === true ? null : 'session.secure',
            $this->hasSecureSessionDomain() ? null : 'session.domain',
            $this->matches('filesystems.default', 's3'),
            $this->config->get('filesystems.disks.s3.throw') === true ? null : 'filesystems.disks.s3.throw',
            $this->config->get('filesystems.disks.s3.report') === true ? null : 'filesystems.disks.s3.report',
            $this->matches('broadcasting.default', 'reverb'),
            $this->matches('ocr.driver', 'textract'),
            $this->hasDeliveringMailer() ? null : 'mail.default',
            $this->hasValidMailFromAddress() ? null : 'mail.from.address',
            $this->hasTrustedProxies() ? null : 'production.trusted_proxies',
            $this->hasAllowedHosts() ? null : 'production.allowed_hosts',
            $this->hasProductionReverbKey() ? null : 'reverb.apps.apps.0.key',
            $this->hasProductionReverbSecret() ? null : 'reverb.apps.apps.0.secret',
            $this->hasHttpsReverbScheme() ? null : 'reverb.apps.apps.0.options.scheme',
            $this->hasReverbTlsEnabled() ? null : 'reverb.apps.apps.0.options.useTLS',
            $this->hasRestrictedReverbOrigins() ? null : 'reverb.apps.apps.0.allowed_origins',
        ], static fn (?string $key): bool => $key !== null));

        if ($invalidKeys !== []) {
            throw new LogicException(sprintf(
                'Unsafe production configuration: %s.',
                implode(', ', $invalidKeys),
            ));
        }
    }

    private function matches(string $key, string $expected): ?string
    {
        return $this->config->get($key) === $expected ? null : $key;
    }

    private function isHttpsUrl(): bool
    {
        $url = $this->config->get('app.url');

        return is_string($url) && str_starts_with($url, 'https://');
    }

    private function hasDeliveringMailer(): bool
    {
        $mailer = $this->config->get('mail.default');

        return is_string($mailer) && ! in_array($mailer, ['array', 'log'], true);
    }

    private function hasValidMailFromAddress(): bool
    {
        $address = $this->config->get('mail.from.address');

        return is_string($address) && filter_var($address, FILTER_VALIDATE_EMAIL) !== false;
    }

    private function hasSecureSessionDomain(): bool
    {
        $applicationHost = parse_url((string) $this->config->get('app.url'), PHP_URL_HOST);

        return is_string($applicationHost)
            && $applicationHost !== ''
            && $this->config->get('session.domain') === $applicationHost;
    }

    /**
     * Production must name specific proxy IPs/CIDRs (or Symfony's
     * PRIVATE_SUBNETS). REMOTE_ADDR / * trust whoever opened the TCP
     * connection and let clients spoof X-Forwarded-For.
     */
    private function hasTrustedProxies(): bool
    {
        $proxies = $this->config->get('production.trusted_proxies');

        if (! is_string($proxies) || mb_trim($proxies) === '') {
            return false;
        }

        $entries = array_values(array_filter(
            array_map(trim(...), explode(',', $proxies)),
            static fn (string $entry): bool => $entry !== '',
        ));

        if ($entries === []) {
            return false;
        }

        return array_all($entries, fn (string $entry): bool => ! in_array($entry, ['REMOTE_ADDR', '*', '**', '0.0.0.0/0', '::/0'], true));
    }

    private function hasAllowedHosts(): bool
    {
        $hosts = $this->config->get('production.allowed_hosts');

        return is_array($hosts) && $hosts !== [] && ! $this->allowsUntrustedHost($hosts);
    }

    /**
     * @param  array<array-key, mixed>  $hostPatterns
     */
    private function allowsUntrustedHost(array $hostPatterns): bool
    {
        foreach ($hostPatterns as $pattern) {
            if (! is_string($pattern)) {
                continue;
            }

            foreach (self::UNTRUSTED_CANARY_HOSTS as $canary) {
                if (@preg_match('{'.$pattern.'}i', $canary) === 1) {
                    return true;
                }
            }
        }

        return false;
    }

    private function hasProductionReverbKey(): bool
    {
        $key = $this->config->get('reverb.apps.apps.0.key');

        return is_string($key) && $key !== '' && $key !== self::DEV_REVERB_APP_KEY;
    }

    private function hasProductionReverbSecret(): bool
    {
        $secret = $this->config->get('reverb.apps.apps.0.secret');

        return is_string($secret) && $secret !== '' && $secret !== self::DEV_REVERB_APP_SECRET;
    }

    private function hasHttpsReverbScheme(): bool
    {
        return $this->config->get('reverb.apps.apps.0.options.scheme') === 'https';
    }

    private function hasReverbTlsEnabled(): bool
    {
        return $this->config->get('reverb.apps.apps.0.options.useTLS') === true;
    }

    private function hasRestrictedReverbOrigins(): bool
    {
        $origins = $this->config->get('reverb.apps.apps.0.allowed_origins');

        return is_array($origins) && $origins !== [] && ! in_array('*', $origins, true);
    }

    private function isPackageDiscovery(): bool
    {
        if (! $this->application->runningInConsole()) {
            return false;
        }

        // Read live CLI argv — Request::server() is a request snapshot and misses Composer.
        $arguments = $_SERVER['argv'] ?? [];

        return is_array($arguments) && in_array('package:discover', $arguments, true);
    }
}
