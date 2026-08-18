import type { ReactNode } from 'react';
import { I18nProvider } from 'react-aria-components';
import { useTimezoneSync } from '@/hooks/use-timezone-sync';
import { useTranslation } from '@/hooks/use-translation';

/**
 * Carries the app's regional signals: hands the locale to React Aria, and reports
 * the browser's timezone back to the server.
 *
 * Without the locale, React Aria falls back to the *browser's* locale, so a Dutch
 * user on an en-US browser would get English month names in the date picker
 * and English screen-reader announcements — the exact bug the old calendar had
 * with its hardcoded `toLocaleString("default", …)`.
 *
 * The timezone sync lives here because this component wraps every page, including
 * the portal, legal, and error pages whose layouts are null. Its effect never runs
 * during a server render, so no browser global is touched there.
 *
 * This is a component rather than an inline provider because both values come
 * from Inertia page props, which are only readable inside the app tree.
 */
export default function AriaLocaleProvider({
    children,
}: {
    children: ReactNode;
}) {
    const { locale } = useTranslation();

    useTimezoneSync();

    return <I18nProvider locale={locale}>{children}</I18nProvider>;
}
