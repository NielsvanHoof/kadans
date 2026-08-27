<?php

declare(strict_types=1);

namespace App\Platform\Http\SharedProps;

use App\Accounts\Actions\Localization\LoadFrontendTranslationsAction;
use App\Accounts\Enums\Locale;
use Illuminate\Http\Request;
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
    public function __invoke(Request $request, string $locale): array
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
            'translations' => Inertia::once(
                fn (): array => $this->loadFrontendTranslations->handle($locale),
            )->fresh(
                $request->hasSession()
               ? $request->session()->pull('inertia.refresh.translations', false)
               : false,
            ),
        ];
    }
}
