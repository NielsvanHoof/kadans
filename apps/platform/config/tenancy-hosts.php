<?php

declare(strict_types=1);

$applicationHost = parse_url((string) env('APP_URL', 'http://localhost'), PHP_URL_HOST);
$applicationHost = is_string($applicationHost) ? mb_strtolower($applicationHost) : '';

/*
 * Base domain for {slug}.{base} tenant hosts. Defaults to stripping the first
 * label from APP_URL (app.qompose.nl → qompose.nl). Override with
 * TENANCY_BASE_DOMAIN when the central host is not "{label}.{base}".
 */
$configuredBase = env('TENANCY_BASE_DOMAIN');
$baseDomain = is_string($configuredBase) && $configuredBase !== ''
    ? mb_strtolower($configuredBase)
    : (static function (string $host): string {
        if ($host === '' || ! str_contains($host, '.')) {
            return $host;
        }

        return implode('.', array_slice(explode('.', $host), 1));
    })($applicationHost);

$extraCentral = array_values(array_filter(array_map(
    static fn (string $host): string => mb_strtolower(mb_trim($host)),
    explode(',', (string) env('TENANCY_CENTRAL_DOMAINS', '')),
), static fn (string $host): bool => $host !== ''));

$centralHosts = array_values(array_unique(array_filter([
    $applicationHost,
    ...$extraCentral,
])));

return [
    'base_domain' => $baseDomain,

    /*
     * Hosts that never carry a tenant (login, firm picker, settings, …).
     */
    'central_domains' => $centralHosts,

    /*
     * First labels that must never become firm slugs / tenant subdomains.
     */
    'reserved_subdomains' => [
        'www',
        'app',
        'api',
        'mail',
        'smtp',
        'imap',
        'pop',
        'ftp',
        'cdn',
        'static',
        'assets',
        'status',
        'health',
        'admin',
        'portal',
        'support',
        'docs',
        'help',
        'billing',
        'checkout',
        'auth',
        'sso',
        'login',
        'account',
        'accounts',
        'secure',
        'dev',
        'staging',
        'test',
        'local',
        'localhost',
        'm',
        'mobile',
        'ws',
        'wss',
        'reverb',
        'realtime',
        'echo',
        'grafana',
        'metrics',
        'monitor',
    ],
];
