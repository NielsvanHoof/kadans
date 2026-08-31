<?php

declare(strict_types=1);

namespace App\Integrations\Actions;

use App\Accounts\Models\User;
use App\Integrations\Enums\OAuthProvider;
use App\Integrations\Models\OAuthConnection;

/**
 * Remove a linked OAuth provider from a user (settings disconnect).
 */
final readonly class UnlinkOAuthConnectionAction
{
    public function handle(User $user, OAuthProvider $provider): void
    {
        OAuthConnection::query()
            ->where('user_id', $user->id)
            ->where('provider', $provider)
            ->delete();
    }
}
