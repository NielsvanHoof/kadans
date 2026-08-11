<?php

declare(strict_types=1);

$applicationHost = parse_url((string) env('APP_URL', ''), PHP_URL_HOST);
$applicationHost = is_string($applicationHost) ? mb_strtolower($applicationHost) : '';

$baseDomain = (string) (
    env('TENANCY_BASE_DOMAIN')
    ?: (
        is_string($applicationHost) && $applicationHost !== '' && str_contains($applicationHost, '.')
            ? implode('.', array_slice(explode('.', $applicationHost), 1))
            : $applicationHost
    )
);
$baseDomain = mb_strtolower($baseDomain);

$allowedHosts = [];

if ($applicationHost !== '') {
    $allowedHosts[] = '^'.preg_quote($applicationHost, '/').'$';
}

// Tenant subdomains: exactly one label before the base domain.
if ($baseDomain !== '' && $baseDomain !== $applicationHost) {
    $allowedHosts[] = '^[a-z0-9]([a-z0-9-]*[a-z0-9])?\.'.preg_quote($baseDomain, '/').'$';
}

return [
    // Comma-separated proxy IPs/CIDRs, or PRIVATE_SUBNETS. Do not use
    // REMOTE_ADDR / * in production — ProductionConfigurationValidator rejects them.
    'trusted_proxies' => env('TRUSTED_PROXIES'),
    'allowed_hosts' => $allowedHosts,
];
