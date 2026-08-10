<?php

declare(strict_types=1);

namespace App\Platform\Services\Production;

use App\Accounts\Enums\TwoFactorEnforcement;
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

    /**
     * Monolog drivers that only ever write to local disk.
     *
     * @var list<string>
     */
    private const array FILE_LOG_DRIVERS = ['single', 'daily'];

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
            // Both provider drivers, not just OCR: a mock transcriber silently
            // produces fabricated transcripts instead of failing.
            $this->matches('transcription.driver', 'transcribe'),
            $this->hasProductionSearchDriver() ? null : 'scout.driver',
            $this->hasErrorReporting() ? null : 'sentry.dsn',
            $this->unshippedLogChannelKey(),
            $this->hasSafeSessionSameSite() ? null : 'session.same_site',
            $this->enforcesTwoFactor() ? null : 'security.two_factor.enforcement',
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

    /**
     * Errors must reach somewhere a human looks. With an empty DSN the Sentry
     * SDK disables itself silently, so every production exception is reported
     * to nothing — and nothing about the running app looks wrong.
     */
    private function hasErrorReporting(): bool
    {
        $dsn = $this->config->get('sentry.dsn');

        return is_string($dsn) && mb_trim($dsn) !== '';
    }

    /**
     * At least one log channel must leave the machine. `single`/`daily` write
     * into a container filesystem that is discarded on the next deploy, which
     * is indistinguishable from having no logs at all.
     *
     * Returns the key an operator actually has to change — the stack's channel
     * list when a stack is misconfigured, not the `logging.default` that points
     * at it.
     */
    private function unshippedLogChannelKey(): ?string
    {
        $default = $this->config->get('logging.default');

        if (! is_string($default) || $default === '') {
            return 'logging.default';
        }

        if ($default !== 'stack') {
            return $this->channelShipsOffHost($default) ? null : 'logging.default';
        }

        $channels = $this->config->get('logging.channels.stack.channels');

        if (! is_array($channels) || $channels === []) {
            return 'logging.channels.stack.channels';
        }

        $ships = array_any(
            $channels,
            fn (mixed $channel): bool => is_string($channel) && $this->channelShipsOffHost($channel),
        );

        return $ships ? null : 'logging.channels.stack.channels';
    }

    private function channelShipsOffHost(string $channel): bool
    {
        $driver = $this->config->get("logging.channels.{$channel}.driver");

        return is_string($driver) && ! in_array($driver, self::FILE_LOG_DRIVERS, true);
    }

    /**
     * A platform holding client financial records must not run with 2FA
     * optional. Any mode other than `off` is accepted — choosing between
     * all-staff and permission-keyed enforcement is the operator's call.
     */
    private function enforcesTwoFactor(): bool
    {
        $enforcement = $this->config->get('security.two_factor.enforcement');

        return $enforcement !== TwoFactorEnforcement::Off->value
            && TwoFactorEnforcement::tryFrom(is_string($enforcement) ? $enforcement : '') instanceof TwoFactorEnforcement;
    }

    /**
     * `none` would let the session cookie ride cross-site requests, which is
     * the CSRF exposure SameSite exists to close.
     */
    private function hasSafeSessionSameSite(): bool
    {
        return in_array($this->config->get('session.same_site'), ['lax', 'strict'], true);
    }

    /**
     * `collection` filters in PHP over every row and `null` silently returns
     * nothing — both make workspace search quietly wrong rather than broken.
     */
    private function hasProductionSearchDriver(): bool
    {
        $driver = $this->config->get('scout.driver');

        return is_string($driver) && ! in_array($driver, ['collection', 'null'], true);
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
