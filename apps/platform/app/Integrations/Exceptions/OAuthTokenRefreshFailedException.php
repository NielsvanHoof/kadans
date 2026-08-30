<?php

declare(strict_types=1);

namespace App\Integrations\Exceptions;

use Exception;

/**
 * Google OAuth token cannot be refreshed — user must reconnect.
 */
final class OAuthTokenRefreshFailedException extends Exception
{
    //
}
