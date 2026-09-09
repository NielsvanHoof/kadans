<?php

declare(strict_types=1);

namespace App\Accounts\Actions\Localization;

use App\Accounts\Models\User;
use App\Accounts\Support\Localization\DisplayTimezones;
use Illuminate\Http\Request;

use function is_string;

/**
 * The timezone absolute timestamps are presented in.
 *
 * Precedence mirrors ResolveApplicationLocaleAction: an explicit account setting
 * wins, then the zone the browser reported via cookie, then the configured
 * default. There is no Accept-Language equivalent for zones — and no header
 * carries one — so the cookie is the only detection channel.
 *
 * Every rung is validated, so a hostile cookie or a typo'd env var degrades to
 * the next source instead of reaching a formatter and throwing.
 */
final class ResolveApplicationTimezoneAction
{
    public function handle(Request $request): string
    {
        $user = $request->user();

        if ($user instanceof User) {
            // Read the raw attribute: the column is absent on models that never
            // selected it, and preventsAccessingMissingAttributes() is enabled.
            $storedTimezone = $user->getAttributes()['timezone'] ?? null;

            $userTimezone = DisplayTimezones::sanitize(
                is_string($storedTimezone) ? $storedTimezone : null,
            );

            if ($userTimezone !== null) {
                return $userTimezone;
            }
        }

        $cookieValue = $request->cookie('timezone');

        $cookieTimezone = DisplayTimezones::sanitize(
            is_string($cookieValue) ? $cookieValue : null,
        );

        if ($cookieTimezone !== null) {
            return $cookieTimezone;
        }

        return DisplayTimezones::default();
    }
}
