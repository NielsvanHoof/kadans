<?php

declare(strict_types=1);

namespace App\Accounts\Listeners;

use App\Shared\Observability\ObservabilityContext;
use Illuminate\Auth\Events\Authenticated;
use Illuminate\Contracts\Auth\Authenticatable;

use function is_int;
use function is_string;

/**
 * Stamp the authenticated user id onto logs and queued jobs.
 *
 * Only the id — never the email or name. Context is written into log output and
 * serialized into queue payloads, neither of which should carry personal data.
 */
final readonly class AddAuthenticatedUserToContext
{
    public function handle(Authenticated $event): void
    {
        $user = $event->user;

        if (! $user instanceof Authenticatable) {
            return;
        }

        $identifier = $user->getAuthIdentifier();

        if (! is_int($identifier) && ! is_string($identifier)) {
            return;
        }

        ObservabilityContext::rememberUser($identifier);
    }
}
