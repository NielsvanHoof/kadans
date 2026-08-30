<?php

declare(strict_types=1);

namespace App\Integrations\Actions;

use App\Accounts\Models\User;
use App\Integrations\Enums\OAuthProvider;
use App\Integrations\Exceptions\OAuthTokenRefreshFailedException;
use App\Integrations\Http\Connectors\Google\GoogleConnector;
use App\Integrations\Models\OAuthConnection;
use Illuminate\Support\Facades\Date;

use function is_string;

/**
 * Resolve a Google Saloon connector with a fresh access token for the user.
 */
final readonly class ResolveGoogleConnectorAction
{
    public function __construct(
        private RefreshOAuthAccessTokenAction $refreshOAuthAccessToken,
    ) {}

    /**
     * @throws OAuthTokenRefreshFailedException
     */
    public function handle(User $user): GoogleConnector
    {
        $connection = OAuthConnection::query()
            ->where('user_id', $user->id)
            ->where('provider', OAuthProvider::Google)
            ->first();

        if (! $connection instanceof OAuthConnection) {
            throw new OAuthTokenRefreshFailedException(
                __('Connect Google in Integrations to use calendar features.'),
            );
        }

        if (! OAuthProvider::Google->hasWorkspaceScopes($connection->scopes)) {
            throw new OAuthTokenRefreshFailedException(
                __('Reconnect Google to enable calendar and Drive access.'),
            );
        }

        $accessToken = $connection->access_token;

        if (! is_string($accessToken) || $accessToken === '') {
            throw new OAuthTokenRefreshFailedException(
                __('Reconnect Google to continue using calendar features.'),
            );
        }

        // Refresh a couple of minutes early so Calendar/Drive calls do not race expiry.
        $expiresAt = $connection->token_expires_at;
        $needsRefresh = $expiresAt === null
            || $expiresAt->lessThanOrEqualTo(Date::now()->addMinutes(2));

        if ($needsRefresh) {
            $connection = $this->refreshOAuthAccessToken->handle($connection);
            $accessToken = $connection->access_token;

            if (! is_string($accessToken) || $accessToken === '') {
                throw new OAuthTokenRefreshFailedException(
                    __('Reconnect Google to continue using calendar features.'),
                );
            }
        }

        return new GoogleConnector($accessToken);
    }
}
