<?php

declare(strict_types=1);

$applicationHost = parse_url((string) env('APP_URL', ''), PHP_URL_HOST);

return [
    // Comma-separated proxy IPs/CIDRs, or PRIVATE_SUBNETS. Do not use
    // REMOTE_ADDR / * in production — ProductionConfigurationValidator rejects them.
    'trusted_proxies' => env('TRUSTED_PROXIES'),
    'allowed_hosts' => is_string($applicationHost) && $applicationHost !== ''
        ? ['^'.preg_quote($applicationHost).'$']
        : [],
];
