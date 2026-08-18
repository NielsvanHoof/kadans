<?php

declare(strict_types=1);

namespace App\Accounts\Actions\Localization;

use App\Accounts\Enums\Locale;
use App\Accounts\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;

use function is_string;

final class ResolveApplicationLocaleAction
{
    public function handle(Request $request): string
    {
        $user = $request->user();

        if ($user instanceof User) {
            $storedLocale = $user->getAttributes()['locale'] ?? null;

            if (is_string($storedLocale) && $storedLocale !== '') {
                $userLocale = Locale::tryFrom($storedLocale);

                if ($userLocale instanceof Locale) {
                    return $userLocale->value;
                }
            }
        }

        $cookieValue = $request->cookie('locale', '');
        $cookieLocale = is_string($cookieValue) ? Locale::tryFrom($cookieValue) : null;

        if ($cookieLocale instanceof Locale) {
            return $cookieLocale->value;
        }

        $preferredLocale = $request->getPreferredLanguage(Locale::values());

        if (is_string($preferredLocale)) {
            return $preferredLocale;
        }

        return Config::string('app.locale', Locale::English->value);
    }
}
