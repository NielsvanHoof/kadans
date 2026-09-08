<?php

declare(strict_types=1);

namespace App\Platform\Support\Observability;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;

use function is_string;

/**
 * Debugbar storage.open resolver that is safe for Octane/FrankenPHP worker boot.
 *
 * Debugbar's default (null) calls IpUtils::isPrivateIp(clientIp). FrankenPHP boots
 * workers with an empty REMOTE_ADDR, and Symfony throws ValueError on "".
 *
 * Config must stay serializable for `config:cache` / `optimize`, so we register
 * this class name instead of a Closure. Debugbar calls resolve() when present.
 */
final class DebugbarStorageOpen
{
    public static function resolve(Request $request): bool
    {
        $ip = $request->getClientIp();

        // Empty client IP happens during Octane worker boot — treat as closed.
        if (! is_string($ip) || $ip === '') {
            return false;
        }

        return IpUtils::isPrivateIp($ip);
    }
}
