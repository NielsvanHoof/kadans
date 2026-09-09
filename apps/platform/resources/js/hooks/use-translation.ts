import { usePage } from '@inertiajs/react';
import {
    interpolateTranslation,
    type TranslationReplacements,
} from '@/lib/interpolate-translation';

type AvailableLocale = {
    code: string;
    label: string;
};

export function useTranslation() {
    const { translations, locale, available_locales } = usePage().props;

    const t = (
        key: string,
        replacements: TranslationReplacements = {},
    ): string => {
        const dictionary = translations as Record<string, string>;
        const missing = !(key in dictionary);

        if (missing && import.meta.env.DEV) {
            console.error(`Missing translation key: ${key}`);
        }

        return interpolateTranslation(dictionary[key] ?? key, replacements);
    };

    return {
        t,
        locale: locale as string,
        availableLocales: available_locales as AvailableLocale[],
    };
}
