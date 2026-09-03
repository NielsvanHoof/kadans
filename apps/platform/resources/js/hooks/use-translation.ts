import { usePage } from '@inertiajs/react';

type AvailableLocale = {
    code: string;
    label: string;
};

type TranslationReplacements = Record<string, string | number>;

export function useTranslation() {
    const { translations, locale, available_locales } = usePage().props;

    const t = (
        key: string,
        replacements: TranslationReplacements = {},
    ): string => {
        const dictionary = translations as Record<string, string>;
        const missing = !(key in dictionary);

        // In en, a missing key renders identically to a working translation —
        // the English sentence is both the key and the fallback. Surface it
        // in development so copy edits cannot silently orphan Dutch.
        if (missing && import.meta.env.DEV) {
            console.error(`Missing translation key: ${key}`);
        }

        let translation = dictionary[key] ?? key;

        // replaceAll: a string with `:count` twice must substitute both.
        for (const [placeholder, value] of Object.entries(replacements)) {
            translation = translation.replaceAll(
                `:${placeholder}`,
                String(value),
            );
        }

        return translation;
    };

    return {
        t,
        locale: locale as string,
        availableLocales: available_locales as AvailableLocale[],
    };
}
