import { router } from '@inertiajs/react';
import { Languages } from 'lucide-react';
import LocaleController from '@/actions/App/Accounts/Http/Controllers/LocaleController';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuGroup,
    DropdownMenuLabel,
    DropdownMenuRadioGroup,
    DropdownMenuRadioItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useTranslation } from '@/hooks/use-translation';
import { setClientCookie } from '@/lib/set-client-cookie';

/**
 * Compact header language control with the Languages (A/文) glyph.
 * Syncs localStorage + cookie + server the same way as settings LocaleTabs.
 */
export default function LocaleMenu() {
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
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button
                    variant="ghost"
                    size="icon"
                    className="size-9"
                    aria-label={t('Language')}
                >
                    <Languages className="size-5" aria-hidden="true" />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="min-w-40">
                <DropdownMenuGroup>
                    <DropdownMenuLabel>{t('Language')}</DropdownMenuLabel>
                    <DropdownMenuRadioGroup
                        value={locale}
                        onValueChange={updateLocale}
                    >
                        {availableLocales.map(({ code, label }) => (
                            <DropdownMenuRadioItem key={code} value={code}>
                                {t(label)}
                            </DropdownMenuRadioItem>
                        ))}
                    </DropdownMenuRadioGroup>
                </DropdownMenuGroup>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
