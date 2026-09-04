<?php

declare(strict_types=1);

namespace App\Accounts\Exceptions;

use RuntimeException;

/**
 * A per-domain translation file is missing, empty, or unreadable.
 *
 * Replaces the old silent `[]` fallback: an incomplete copy set must 500
 * every page rather than render English keys inside Dutch UI.
 */
final class MissingTranslationsException extends RuntimeException
{
    public static function forPath(string $path): self
    {
        return new self("Missing or unreadable translation file [{$path}].");
    }
}
