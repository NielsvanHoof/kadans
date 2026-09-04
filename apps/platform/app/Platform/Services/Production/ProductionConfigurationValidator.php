<?php

declare(strict_types=1);

namespace App\Platform\Services\Production;

use App\Accounts\Enums\Locale;
use App\Accounts\Enums\TwoFactorEnforcement;
use App\DocumentProcessing\Jobs\ProcessUploadedDocumentJob;
use App\Dossiers\Jobs\GenerateDossierExportJob;
use App\Platform\Jobs\GenerateDataSubjectExportJob;
use App\Shared\Support\Localization\TranslationDomains;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Uri;
use LogicException;

use function in_array;
use function is_array;
use function is_int;
use function is_numeric;
use function is_readable;
use function is_string;
use function max;
use function mb_trim;
use function sprintf;
use function str_contains;

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

    /**
     * Inference providers a shipped environment may send workspace data to.
     *
     * This is a sub-processor allowlist, not a performance preference. The
     * document overview agent sends OCR field values from payslips, bank
     * statements and identity documents, so which provider answers is the same
     * class of question as which bucket holds the file. decision-11.
     *
     * @var list<string>
     */
    private const array APPROVED_AI_PROVIDERS = ['bedrock'];

    /**
     * Model-id vendor fragments no shipped environment may resolve to.
     *
     * Anthropic on Bedrock needs an account-level use-case submission this
     * account has not made. Until it clears, those models answer with a `404`
     * whose body is the real reason — which reads like a wrong model id, so it
     * cost an afternoon once already (.ai/rules/ai.md). Nova is what we can
     * actually call, and `infra/src/config.ts` no longer grants the Anthropic
     * ARNs, so an env var pointing here would fail in the worker rather than at
     * boot. Matching a fragment rather than a prefix covers the bare
     * `anthropic.*` ids and the `eu.` / `us.` inference-profile forms alike.
     *
     * @var list<string>
     */
    private const array DISALLOWED_AI_MODEL_FRAGMENTS = ['anthropic.'];

    /**
     * Environments this validator guards. Staging ships the same image and
     * serves the same kind of data as production, so it gets the same checks.
     *
     * @var list<string>
     */
    private const array SHIPPED_ENVIRONMENTS = ['production', 'staging'];

    public function __construct(
        private Application $application,
        private Repository $config,
    ) {}

    public function validate(): void
    {
        if (
            ! $this->runsUnderShippedEnvironment() ||
            $this->isPackageDiscovery()
        ) {
            return;
        }

        $invalidKeys = array_values(
            array_filter(
                [
                    $this->config->get('app.debug') === false
                        ? null
                        : 'app.debug',
                    $this->isHttpsUrl() ? null : 'app.url',
                    $this->hasRetryAfterAboveJobTimeouts()
                        ? null
                        : 'queue.connections.redis.retry_after',
                    $this->hasVerifiedDatabaseTls()
                        ? null
                        : 'database.connections.pgsql.sslmode',
                    $this->hasDatabaseRootCertificate()
                        ? null
                        : 'database.connections.pgsql.sslrootcert',
                    $this->matches('database.redis.default.scheme', 'tls'),
                    $this->matches('database.redis.cache.scheme', 'tls'),
                    $this->matches('database.redis.session.scheme', 'tls'),
                    $this->matches('session.driver', 'redis'),
                    $this->hasDedicatedSessionRedisConnection()
                        ? null
                        : 'session.connection',
                    $this->hasSessionRedisIsolatedFromDefault()
                        ? null
                        : 'database.redis.session.database',
                    $this->config->get('session.encrypt') === true
                        ? null
                        : 'session.encrypt',
                    $this->config->get('session.secure') === true
                        ? null
                        : 'session.secure',
                    $this->hasSecureSessionDomain() ? null : 'session.domain',
                    $this->matches('filesystems.default', 's3'),
                    $this->config->get('filesystems.disks.s3.throw') === true
                        ? null
                        : 'filesystems.disks.s3.throw',
                    $this->config->get('filesystems.disks.s3.report') === true
                        ? null
                        : 'filesystems.disks.s3.report',
                    $this->matches('broadcasting.default', 'reverb'),
                    $this->matches('ocr.driver', 'textract'),
                    // Both provider drivers, not just OCR: a mock transcriber silently
                    // produces fabricated transcripts instead of failing.
                    $this->matches('transcription.driver', 'transcribe'),
                    $this->hasProductionSearchDriver() ? null : 'scout.driver',
                    $this->unapprovedAiProviderKey(),
                    $this->disallowedAiModelKey(),
                    $this->hasHorizonAllowList()
                        ? null
                        : 'horizon.allowed_emails',
                    $this->hasErrorReporting() ? null : 'sentry.dsn',
                    $this->hasBrowserErrorReporting()
                        ? null
                        : 'services.sentry.browser_dsn',
                    $this->unshippedLogChannelKey(),
                    $this->hasSafeSessionSameSite()
                        ? null
                        : 'session.same_site',
                    $this->hasPortalIdleWindowInsideSessionLifetime()
                        ? null
                        : 'portal.session_idle_minutes',
                    $this->enforcesTwoFactor()
                        ? null
                        : 'security.two_factor.enforcement',
                    $this->config->get('security.malware_scanning.enabled') ===
                    true
                        ? null
                        : 'security.malware_scanning.enabled',
                    $this->hasDeliveringMailer() ? null : 'mail.default',
                    $this->hasValidMailFromAddress()
                        ? null
                        : 'mail.from.address',
                    $this->hasTrustedProxies()
                        ? null
                        : 'production.trusted_proxies',
                    $this->hasAllowedHosts()
                        ? null
                        : 'production.allowed_hosts',
                    $this->hasProductionReverbKey()
                        ? null
                        : 'reverb.apps.apps.0.key',
                    $this->hasProductionReverbSecret()
                        ? null
                        : 'reverb.apps.apps.0.secret',
                    $this->hasHttpsReverbScheme()
                        ? null
                        : 'reverb.apps.apps.0.options.scheme',
                    $this->hasReverbTlsEnabled()
                        ? null
                        : 'reverb.apps.apps.0.options.useTLS',
                    $this->hasRestrictedReverbOrigins()
                        ? null
                        : 'reverb.apps.apps.0.allowed_origins',
                    $this->incompleteOAuthProviderKey('google'),
                    $this->incompleteOAuthProviderKey('microsoft'),
                    // Incomplete copy must fail the deploy, not the first page view —
                    // LoadFrontendTranslationsAction throws the same class of fatal.
                    $this->missingTranslationFileKey(),
                ],
                static fn (?string $key): bool => $key !== null,
            ),
        );

        if ($invalidKeys !== []) {
            throw new LogicException(
                sprintf(
                    'Unsafe production configuration: %s.',
                    implode(', ', $invalidKeys),
                ),
            );
        }
    }

    /**
     * Somebody must be able to open Horizon, and it must not be everybody.
     *
     * The dashboard renders queue payloads and failed-job exception traces and
     * is not tenant-scoped, so the allowlist is the only boundary around one
     * firm's job data. An empty list denies everyone, which is safe but silent —
     * the operator discovers it by being locked out during an incident. Failing
     * boot instead turns that into a deploy-time error, and matches how every
     * other externally-visible setting here behaves.
     */
    private function hasHorizonAllowList(): bool
    {
        $allowed = $this->config->get('horizon.allowed_emails');

        if (! is_array($allowed)) {
            return false;
        }

        return array_any(
            $allowed,
            fn ($email): bool => is_string($email) && mb_trim($email) !== '',
        );
    }

    /**
     * The first agent configured to send workspace data somewhere unapproved.
     *
     * Resolution mirrors `ResolvesConfiguredModel::provider()` exactly — agent
     * slot first, `ai.default` when it is blank — because the hazard is precisely
     * that fallback. Every agent names a provider today, so `ai.default` is
     * unreachable; blank one env var and that agent silently starts sending client
     * document fields wherever the default points. Checking the resolved value
     * rather than the literal config catches both halves.
     */
    private function unapprovedAiProviderKey(): ?string
    {
        $agents = $this->config->get('ai.agents');

        if (! is_array($agents)) {
            return 'ai.agents';
        }

        foreach ($agents as $agent => $settings) {
            $configured = is_array($settings)
                ? $settings['provider'] ?? null
                : null;

            $resolved =
                is_string($configured) && mb_trim($configured) !== ''
                    ? $configured
                    : $this->config->get('ai.default');

            if (
                ! is_string($resolved) ||
                ! in_array($resolved, self::APPROVED_AI_PROVIDERS, true)
            ) {
                return sprintf('ai.agents.%s.provider', (string) $agent);
            }
        }

        return null;
    }

    /**
     * The first agent configured to resolve to a model we cannot invoke.
     *
     * Resolution mirrors `ResolvesConfiguredModel::model()`: the agent's own
     * slot, then the provider's default text model when that slot is blank.
     * Checking the resolved value is the point — every agent but
     * `document_overview` leaves `model` unset, so a single edit to the Bedrock
     * provider default would reroute all of them at once.
     *
     * The provider default is checked on its own afterwards, so it cannot hide
     * behind a set of agents that all name their own model.
     */
    private function disallowedAiModelKey(): ?string
    {
        $agents = $this->config->get('ai.agents');

        if (! is_array($agents)) {
            return 'ai.agents';
        }

        foreach ($agents as $agent => $settings) {
            $configured = is_array($settings)
                ? $settings['model'] ?? null
                : null;

            if (! is_string($configured) || mb_trim($configured) === '') {
                continue;
            }

            if ($this->isDisallowedAiModel($configured)) {
                return sprintf('ai.agents.%s.model', (string) $agent);
            }
        }

        foreach (self::APPROVED_AI_PROVIDERS as $provider) {
            $key = sprintf('ai.providers.%s.models.text.default', $provider);
            $default = $this->config->get($key);

            if (is_string($default) && $this->isDisallowedAiModel($default)) {
                return $key;
            }
        }

        return null;
    }

    private function isDisallowedAiModel(string $model): bool
    {
        return array_any(
            self::DISALLOWED_AI_MODEL_FRAGMENTS,
            fn (string $fragment): bool => str_contains(
                mb_strtolower($model),
                $fragment,
            ),
        );
    }

    /**
     * Sessions on the default Redis connection share FLUSHDB with Horizon
     * unique locks and the queue. The dedicated `session` connection is
     * what keeps a `FLUSHDB` on DB 0 from logging everyone out.
     */
    private function hasDedicatedSessionRedisConnection(): bool
    {
        if ($this->config->get('session.driver') !== 'redis') {
            return true;
        }

        return $this->config->get('session.connection') === 'session';
    }

    /**
     * Numbered Redis DBs share instance maxmemory — this is logical
     * isolation, matching cache-on-DB-1. The session DB still must not
     * be the same number as the default connection.
     */
    private function hasSessionRedisIsolatedFromDefault(): bool
    {
        if ($this->config->get('session.driver') !== 'redis') {
            return true;
        }

        $sessionDatabase = $this->config->get(
            'database.redis.session.database',
        );
        $defaultDatabase = $this->config->get(
            'database.redis.default.database',
        );

        if (
            $sessionDatabase === null ||
            $sessionDatabase === '' ||
            $defaultDatabase === null ||
            $defaultDatabase === ''
        ) {
            return false;
        }

        return (string) $sessionDatabase !== (string) $defaultDatabase;
    }

    /**
     * Redis releases a reserved job when retry_after elapses. If that is at or
     * below a job's timeout, a second worker runs the same GDPR export (or OCR
     * start) concurrently. ShouldBeUnique only blocks new dispatches.
     */
    private function hasRetryAfterAboveJobTimeouts(): bool
    {
        $retryAfter = $this->config->get('queue.connections.redis.retry_after');

        if (! is_int($retryAfter) && ! is_numeric($retryAfter)) {
            return false;
        }

        return (int) $retryAfter > $this->largestJobTimeoutSeconds();
    }

    private function largestJobTimeoutSeconds(): int
    {
        $transcriptionTimeout = $this->config->get(
            'transcription.timeouts.job',
        );

        return max(
            GenerateDataSubjectExportJob::TIMEOUT_SECONDS,
            GenerateDossierExportJob::TIMEOUT_SECONDS,
            ProcessUploadedDocumentJob::TIMEOUT_SECONDS,
            is_numeric($transcriptionTimeout) ? (int) $transcriptionTimeout : 0,
        );
    }

    /**
     * The Laravel session expires on idleness too, so a portal idle window at or
     * above `session.lifetime` promises the client a window the session store
     * drops first — they would be bounced to the ended page while the portal
     * still believed the session was live.
     */
    private function hasPortalIdleWindowInsideSessionLifetime(): bool
    {
        $idleMinutes = $this->config->get('portal.session_idle_minutes');
        $sessionLifetime = $this->config->get('session.lifetime');

        if (! is_numeric($idleMinutes) || ! is_numeric($sessionLifetime)) {
            return false;
        }

        return (int) $idleMinutes < (int) $sessionLifetime;
    }

    private function matches(string $key, string $expected): ?string
    {
        return $this->config->get($key) === $expected ? null : $key;
    }

    /**
     * Every registered domain must ship both locale files. A missing file
     * would otherwise 500 every page once LoadFrontendTranslationsAction
     * throws — failing boot surfaces it at deploy time instead.
     */
    private function missingTranslationFileKey(): ?string
    {
        foreach (TranslationDomains::NAMES as $domain) {
            foreach (Locale::values() as $locale) {
                $path = $this->application->basePath(
                    TranslationDomains::relativePath($domain, $locale),
                );

                if (! File::isReadable($path) || File::size($path) === 0) {
                    return TranslationDomains::relativePath($domain, $locale);
                }
            }
        }

        return null;
    }

    /**
     * Parsed rather than prefix-matched: a scheme is case-insensitive, so
     * `HTTPS://app.example.com` is valid and a `str_starts_with('https://')`
     * check would have failed boot on it. `Uri::of()` returns a null scheme for
     * unparseable input instead of throwing.
     */
    private function isHttpsUrl(): bool
    {
        $url = $this->config->get('app.url');

        return is_string($url) && Uri::of($url)->scheme() === 'https';
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
     * The same argument as `hasErrorReporting`, for the half of the app that
     * fails where no server-side log can see it. A React render error takes the
     * page down with no PHP exception, no log line, and no failed response —
     * unreported, the only symptom is a user saying the screen went blank.
     *
     * This checks the server's copy of the DSN, which is a proxy: the value the
     * bundle uses was inlined by Vite at build time and cannot be read back at
     * runtime. It catches the case that actually happens — the variable missing
     * from the deploy environment, so neither the build nor the server had it.
     */
    private function hasBrowserErrorReporting(): bool
    {
        $dsn = $this->config->get('services.sentry.browser_dsn');

        return is_string($dsn) && mb_trim($dsn) !== '';
    }

    /**
     * SSO is optional, but a partial IdP app config must not ship: a client_id
     * without a secret (or redirect) fails at login time instead. When client_id
     * is blank, that provider stays off — other services.{provider}.* keys are ignored.
     */
    private function incompleteOAuthProviderKey(string $provider): ?string
    {
        $clientId = $this->config->get('services.'.$provider.'.client_id');

        if (! is_string($clientId) || mb_trim($clientId) === '') {
            return null;
        }

        foreach (
            [
                'services.'.$provider.'.client_secret',
                'services.'.$provider.'.redirect',
            ] as $key
        ) {
            $value = $this->config->get($key);

            if (! is_string($value) || mb_trim($value) === '') {
                return $key;
            }
        }

        return null;
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
            return $this->channelShipsOffHost($default)
                ? null
                : 'logging.default';
        }

        $channels = $this->config->get('logging.channels.stack.channels');

        if (! is_array($channels) || $channels === []) {
            return 'logging.channels.stack.channels';
        }

        $ships = array_any(
            $channels,
            fn (mixed $channel): bool => is_string($channel) &&
                $this->channelShipsOffHost($channel),
        );

        return $ships ? null : 'logging.channels.stack.channels';
    }

    private function channelShipsOffHost(string $channel): bool
    {
        $driver = $this->config->get("logging.channels.{$channel}.driver");

        return is_string($driver) &&
            ! in_array($driver, self::FILE_LOG_DRIVERS, true);
    }

    /**
     * A platform holding client financial records must not run with 2FA
     * optional. Any mode other than `off` is accepted — choosing between
     * all-staff and permission-keyed enforcement is the operator's call.
     */
    private function enforcesTwoFactor(): bool
    {
        $enforcement = $this->config->get('security.two_factor.enforcement');

        return $enforcement !== TwoFactorEnforcement::Off->value &&
            TwoFactorEnforcement::tryFrom(
                is_string($enforcement) ? $enforcement : '',
            ) instanceof TwoFactorEnforcement;
    }

    /**
     * `none` would let the session cookie ride cross-site requests, which is
     * the CSRF exposure SameSite exists to close.
     */
    private function hasSafeSessionSameSite(): bool
    {
        return in_array(
            $this->config->get('session.same_site'),
            ['lax', 'strict'],
            true,
        );
    }

    /**
     * `collection` filters in PHP over every row and `null` silently returns
     * nothing — both make workspace search quietly wrong rather than broken.
     */
    private function hasProductionSearchDriver(): bool
    {
        $driver = $this->config->get('scout.driver');

        return is_string($driver) &&
            ! in_array($driver, ['collection', 'null'], true);
    }

    private function hasDeliveringMailer(): bool
    {
        $mailer = $this->config->get('mail.default');

        return is_string($mailer) && ! in_array($mailer, ['array', 'log'], true);
    }

    private function hasValidMailFromAddress(): bool
    {
        $address = $this->config->get('mail.from.address');

        return is_string($address) &&
            filter_var($address, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * Compared case-insensitively because DNS is: `Uri::of()` lowercases the
     * host it parses, so a `session.domain` of `App.Example.com` would otherwise
     * be rejected despite naming the same host.
     */
    private function hasSecureSessionDomain(): bool
    {
        $applicationHost = Uri::of(
            (string) $this->config->get('app.url'),
        )->host();
        $sessionDomain = $this->config->get('session.domain');

        return is_string($applicationHost) &&
            $applicationHost !== '' &&
            is_string($sessionDomain) &&
            mb_strtolower($sessionDomain) === $applicationHost;
    }

    /**
     * The database is reachable from outside its own network, so the
     * connection has to be both encrypted and authenticated.
     *
     * `prefer` — the Laravel default — negotiates TLS and then silently falls
     * back to plaintext if the server declines, and never checks the
     * certificate either way. `require` encrypts but still trusts whatever
     * answers on the port. Only the `verify-*` modes actually prove the host
     * on the other end is the database.
     *
     * This became load-bearing when compute moved off AWS: the instance is no
     * longer confined to a VPC where the security group was the whole defence.
     */
    private function hasVerifiedDatabaseTls(): bool
    {
        return in_array(
            $this->config->get('database.connections.pgsql.sslmode'),
            ['verify-ca', 'verify-full'],
            true,
        );
    }

    /**
     * `verify-full` without a root certificate has nothing to verify against,
     * and libpq fails the connection at runtime rather than at boot — which
     * would be a healthy-looking deploy that cannot reach its database.
     */
    private function hasDatabaseRootCertificate(): bool
    {
        $path = $this->config->get('database.connections.pgsql.sslrootcert');

        return is_string($path) && $path !== '' && is_readable($path);
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

        $entries = array_values(
            array_filter(
                array_map(trim(...), explode(',', $proxies)),
                static fn (string $entry): bool => $entry !== '',
            ),
        );

        if ($entries === []) {
            return false;
        }

        return array_all(
            $entries,
            fn (string $entry): bool => ! in_array(
                $entry,
                ['REMOTE_ADDR', '*', '**', '0.0.0.0/0', '::/0'],
                true,
            ),
        );
    }

    private function hasAllowedHosts(): bool
    {
        $hosts = $this->config->get('production.allowed_hosts');

        return is_array($hosts) &&
            $hosts !== [] &&
            ! $this->allowsUntrustedHost($hosts);
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

        return is_string($key) &&
            $key !== '' &&
            $key !== self::DEV_REVERB_APP_KEY;
    }

    private function hasProductionReverbSecret(): bool
    {
        $secret = $this->config->get('reverb.apps.apps.0.secret');

        return is_string($secret) &&
            $secret !== '' &&
            $secret !== self::DEV_REVERB_APP_SECRET;
    }

    private function hasHttpsReverbScheme(): bool
    {
        return $this->config->get('reverb.apps.apps.0.options.scheme') ===
            'https';
    }

    private function hasReverbTlsEnabled(): bool
    {
        return $this->config->get('reverb.apps.apps.0.options.useTLS') === true;
    }

    private function hasRestrictedReverbOrigins(): bool
    {
        $origins = $this->config->get('reverb.apps.apps.0.allowed_origins');

        return is_array($origins) &&
            $origins !== [] &&
            ! in_array('*', $origins, true);
    }

    /**
     * Environments that face real traffic and real client data.
     *
     * `isProduction()` alone would let a staging container boot with debug on,
     * plaintext Redis, mock OCR drivers or the checked-in Reverb keys — the
     * exact failures this class exists to catch. Staging is meant to be a
     * rehearsal of production, so it is held to the same configuration.
     */
    private function runsUnderShippedEnvironment(): bool
    {
        return $this->application->environment(self::SHIPPED_ENVIRONMENTS);
    }

    private function isPackageDiscovery(): bool
    {
        if (! $this->application->runningInConsole()) {
            return false;
        }

        // Read live CLI argv — Request::server() is a request snapshot and misses Composer.
        $arguments = $_SERVER['argv'] ?? [];

        return is_array($arguments) &&
            in_array('package:discover', $arguments, true);
    }
}
