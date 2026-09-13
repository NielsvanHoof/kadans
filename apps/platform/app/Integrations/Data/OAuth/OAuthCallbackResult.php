<?php

declare(strict_types=1);

namespace App\Integrations\Data\OAuth;

use App\Accounts\Models\User;
use App\Integrations\Models\OAuthConnection;

/**
 * Outcome of matching / linking a Socialite identity to a Qompose user.
 *
 * Controllers branch on success vs failure; never creates a User.
 */
final readonly class OAuthCallbackResult
{
    private function __construct(
        public bool $successful,
        public ?User $user,
        public ?OAuthConnection $connection,
        public ?string $errorMessage,
    ) {}

    public static function success(User $user, OAuthConnection $connection): self
    {
        return new self(true, $user, $connection, null);
    }

    public static function failure(string $message): self
    {
        return new self(false, null, null, $message);
    }
}
