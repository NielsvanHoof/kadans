<?php

declare(strict_types=1);

namespace App\Integrations\Enums;

use function in_array;
use function is_string;

/**
 * OAuth providers that can link to a Qompose user account.
 *
 * Route params and Socialite driver names use the enum value.
 * Google is built into Socialite; Microsoft uses socialiteproviders/microsoft.
 */
enum OAuthProvider: string
{
    case Google = 'google';
    case Microsoft = 'microsoft';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Providers that may appear on the login / settings screens.
     *
     * @return list<self>
     */
    public static function configured(): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $provider): bool => $provider->isConfigured(),
        ));
    }

    public function label(): string
    {
        return match ($this) {
            self::Google => 'Google',
            self::Microsoft => 'Microsoft',
        };
    }

    /**
     * Socialite scopes for identity (+ Google Calendar/Drive for workspace features).
     *
     * @return list<string>
     */
    public function scopes(): array
    {
        return match ($this) {
            self::Google => [
                'openid',
                'profile',
                'email',
                'https://www.googleapis.com/auth/calendar.events',
                'https://www.googleapis.com/auth/drive.file',
            ],
            self::Microsoft => [
                'openid',
                'profile',
                'email',
                'offline_access',
                'User.Read',
            ],
        };
    }

    /**
     * Scopes required for Calendar suggest + Drive transcript attach.
     *
     * @return list<string>
     */
    public function workspaceScopes(): array
    {
        return match ($this) {
            self::Google => [
                'https://www.googleapis.com/auth/calendar.events',
                'https://www.googleapis.com/auth/drive.file',
            ],
            self::Microsoft => [],
        };
    }

    /**
     * Whether the granted scope list covers workspace (calendar/Drive) features.
     *
     * @param  list<string>|null  $granted
     */
    public function hasWorkspaceScopes(?array $granted): bool
    {
        $required = $this->workspaceScopes();

        if ($required === []) {
            return false;
        }

        if ($granted === null || $granted === []) {
            return false;
        }

        return array_all($required, fn ($scope): bool => in_array($scope, $granted, true));
    }

    /**
     * Extra OAuth authorize query params (e.g. Google offline access).
     *
     * @return array<string, string>
     */
    public function redirectParameters(bool $forceConsent = false): array
    {
        return match ($this) {
            // access_type=offline so we can store a refresh token. Consent is
            // forced when reconnecting for new Calendar/Drive scopes.
            self::Google => [
                'access_type' => 'offline',
                'prompt' => $forceConsent ? 'consent select_account' : 'select_account',
            ],
            self::Microsoft => [],
        };
    }

    /**
     * Whether this provider has credentials configured.
     */
    public function isConfigured(): bool
    {
        $clientId = config('services.'.$this->value.'.client_id');

        return is_string($clientId) && $clientId !== '';
    }
}
