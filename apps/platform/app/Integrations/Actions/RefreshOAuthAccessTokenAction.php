<?php

declare(strict_types=1);

namespace App\Integrations\Actions;

use App\Integrations\Enums\OAuthProvider;
use App\Integrations\Exceptions\OAuthTokenRefreshFailedException;
use App\Integrations\Models\OAuthConnection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;
use Throwable;

use function is_string;

/**
 * Refresh a Google access token using the stored refresh token.
 */
final readonly class RefreshOAuthAccessTokenAction
{
    /**
     * @throws OAuthTokenRefreshFailedException
     */
    public function handle(OAuthConnection $connection): OAuthConnection
    {
        if ($connection->provider !== OAuthProvider::Google) {
            throw new OAuthTokenRefreshFailedException(
                __('Token refresh is not available for :provider yet.', [
                    'provider' => $connection->provider->label(),
                ]),
            );
        }

        $refreshToken = $connection->refresh_token;

        if (! is_string($refreshToken) || $refreshToken === '') {
            throw new OAuthTokenRefreshFailedException(
                __('Reconnect Google to continue using calendar features.'),
            );
        }

        $clientId = config('services.google.client_id');
        $clientSecret = config('services.google.client_secret');

        if (! is_string($clientId) || $clientId === '' || ! is_string($clientSecret) || $clientSecret === '') {
            throw new OAuthTokenRefreshFailedException(
                __('Google sign-in isn\'t set up yet. Contact your administrator.'),
            );
        }

        try {
            $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'refresh_token' => $refreshToken,
                'grant_type' => 'refresh_token',
            ]);
        } catch (Throwable $exception) {
            report($exception);

            throw new OAuthTokenRefreshFailedException(__('Could not refresh the Google connection. Please reconnect.'), $exception->getCode(), previous: $exception);
        }

        if (! $response->successful()) {
            throw new OAuthTokenRefreshFailedException(
                __('Reconnect Google to continue using calendar features.'),
            );
        }

        /** @var array{access_token?: mixed, expires_in?: mixed, refresh_token?: mixed} $payload */
        $payload = $response->json();

        $accessToken = $payload['access_token'] ?? null;

        if (! is_string($accessToken) || $accessToken === '') {
            throw new OAuthTokenRefreshFailedException(
                __('Reconnect Google to continue using calendar features.'),
            );
        }

        $expiresIn = $payload['expires_in'] ?? null;
        $newRefresh = $payload['refresh_token'] ?? null;

        $connection->forceFill([
            'access_token' => $accessToken,
            'refresh_token' => is_string($newRefresh) && $newRefresh !== ''
                ? $newRefresh
                : $connection->refresh_token,
            'token_expires_at' => is_numeric($expiresIn) && (int) $expiresIn > 0
                ? Date::now()->addSeconds((int) $expiresIn)
                : $connection->token_expires_at,
        ])->save();

        return $connection->fresh() ?? $connection;
    }
}
