<?php

declare(strict_types=1);

namespace App\Integrations\Http\Connectors\Google;

use Saloon\Contracts\Authenticator;
use Saloon\Http\Auth\TokenAuthenticator;
use Saloon\Http\Connector;

/**
 * Google APIs (Calendar + Drive) authenticated with a user access token.
 */
final class GoogleConnector extends Connector
{
    public function __construct(
        private readonly string $accessToken,
    ) {}

    public function resolveBaseUrl(): string
    {
        return 'https://www.googleapis.com';
    }

    protected function defaultAuth(): Authenticator
    {
        return new TokenAuthenticator($this->accessToken);
    }
}
