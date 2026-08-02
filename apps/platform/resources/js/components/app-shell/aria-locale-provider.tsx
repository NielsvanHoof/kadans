import type { ReactNode } from 'react';
import { I18nProvider } from 'react-aria-components';
import { useTranslation } from '@/hooks/use-translation';

/**
 * Hands the app's locale to React Aria.
 *
 * Without this, React Aria falls back to the *browser's* locale, so a Dutch
 * user on an en-US browser would get English month names in the date picker
 * and English screen-reader announcements — the exact bug the old calendar had
 * with its hardcoded `toLocaleString("default", …)`.
 *
 * This is a component rather than an inline provider because the locale comes
 * from Inertia page props, which are only readable inside the app tree.
 */
export default function AriaLocaleProvider({
    children,
}: {
    children: ReactNode;
}) {
    const { locale } = useTranslation();

    return <I18nProvider locale={locale}>{children}</I18nProvider>;
}
