export type TranslationReplacements = Record<string, string | number>;

/**
 * Substitute Laravel-style `:placeholder` tokens.
 *
 * Tokens are whole identifiers. Naive `replaceAll(':' + name)` treats
 * `:to` as a prefix of `:total`, which rendered as "15tal" in pagination.
 */
export function interpolateTranslation(
    translation: string,
    replacements: TranslationReplacements,
): string {
    return translation.replace(
        /:([A-Za-z_][A-Za-z0-9_]*)/g,
        (match, name: string): string => {
            if (Object.hasOwn(replacements, name)) {
                return String(replacements[name]);
            }

            return match;
        },
    );
}
