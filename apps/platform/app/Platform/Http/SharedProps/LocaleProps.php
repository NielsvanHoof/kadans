<?php

declare(strict_types=1);

namespace App\Platform\Http\SharedProps;

use App\Accounts\Actions\Localization\LoadFrontendTranslationsAction;
use App\Accounts\Enums\Locale;
use Inertia\Inertia;

/**
 * Locale, the selectable locales, and the frontend translation dictionary.
 */
final readonly class LocaleProps
{
    public function __construct(
        private LoadFrontendTranslationsAction $loadFrontendTranslations,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function __invoke(string $locale): array
    {
        return [
            'locale' => $locale,
            'available_locales' => array_map(
                static fn (Locale $availableLocale): array => [
                    'code' => $availableLocale->value,
                    'label' => $availableLocale->label(),
                ],
                Locale::cases(),
            ),
            /*
             * The once() key carries the locale, so a language change can never
             * collide with the dictionary the client already holds: it sends back
             * `translations.en`, this response advertises `translations.nl`, and
             * the miss makes the server resolve the new dictionary.
             *
             * Keying on the prop name alone left the client holding its first
             * dictionary forever, because `locale` beside it is a regular prop
             * that keeps updating — dates and React Aria followed the switch
             * while every t() string stayed frozen. Staff hid that behind a
             * session flag flashed by LocaleController; portal guests have no
             * such endpoint, and neither do the Accept-Language or bare-cookie
             * paths. Keying on the locale fixes all of them at once, with no
             * flag to remember to set.
             */
            'translations' => Inertia::once(
                fn (): array => $this->loadFrontendTranslations->handle($locale),
            )->as("translations.{$locale}"),
        ];
    }
}
