<?php

declare(strict_types=1);

namespace App\Platform\Http\SharedProps;

use App\Accounts\Actions\Localization\LoadFrontendTranslationsAction;
use App\Accounts\Enums\Locale;

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
            'translations' => fn (): array => $this->loadFrontendTranslations->handle($locale),
        ];
    }
}
