import { router } from '@inertiajs/react';
import LocaleController from '@/actions/App/Accounts/Http/Controllers/LocaleController';
import SegmentedControl from '@/components/segmented-control';
import { useTranslation } from '@/hooks/use-translation';
import { setClientCookie } from '@/lib/set-client-cookie';

/**
 * Locale preference segmented control. Syncs localStorage + cookie + server.
 */
export default function LocaleTabs({ className = '' }: { className?: string }) {
    const { t, locale, availableLocales } = useTranslation();

    const updateLocale = (nextLocale: string): void => {
        if (nextLocale === locale) {
            return;
        }

        localStorage.setItem('locale', nextLocale);
        setClientCookie('locale', nextLocale);

        router.patch(
            LocaleController.update.url(),
            { locale: nextLocale },
            {
                preserveScroll: true,
            },
        );
    };

    return (
        <SegmentedControl
            aria-label={t('Language')}
            className={className}
            value={locale}
            onValueChange={updateLocale}
            options={availableLocales.map(({ code, label }) => ({
                value: code,
                label: t(label),
            }))}
        />
    );
}
