<?php

declare(strict_types=1);

namespace App\Integrations\Http\Controllers\OAuth;

use App\Accounts\Http\Concerns\ResolvesCurrentUser;
use App\Integrations\Enums\OAuthProvider;
use App\Shared\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings page for linked SSO providers (Microsoft first).
 */
final class IntegrationsSettingsController extends Controller
{
    use ResolvesCurrentUser;

    public function edit(): Response
    {
        $user = $this->currentUser();

        return Inertia::render('settings/integrations', [
            'oauthConnections' => $user->oauthConnections()
                ->get()
                ->map(fn ($connection): array => [
                    'provider' => $connection->provider->value,
                    'email' => $connection->email,
                    'has_workspace_scopes' => $connection->provider->hasWorkspaceScopes($connection->scopes),
                ])
                ->values()
                ->all(),
            'oauthProviders' => array_map(
                static fn (OAuthProvider $provider): string => $provider->value,
                OAuthProvider::configured(),
            ),
        ]);
    }
}
