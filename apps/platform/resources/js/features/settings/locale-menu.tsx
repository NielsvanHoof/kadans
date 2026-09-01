import { router } from '@inertiajs/react';
import { Languages } from 'lucide-react';
import LocaleController from '@/actions/App/Accounts/Http/Controllers/LocaleController';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useTranslation } from '@/hooks/use-translation';
import { setClientCookie } from '@/lib/set-client-cookie';

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
        <DropdownMenuTrigger>
            <Button
                variant="ghost"
                size="icon"
                className="size-9"
                aria-label={t('Language')}
            >
                <Languages className="size-5" aria-hidden="true" />
            </Button>
            <DropdownMenu
                placement="bottom end"
                className="min-w-40"
                selectionMode="single"
                selectedKeys={[locale]}
                onSelectionChange={(keys) => {
                    const [next] = Array.from(keys);
                    if (next !== undefined) {
                        updateLocale(String(next));
                    }
                }}
            >
                <DropdownMenuGroup>
                    <DropdownMenuLabel>{t('Language')}</DropdownMenuLabel>

                    {availableLocales.map(({ code, label }) => (
                        <DropdownMenuItem key={code} id={code}>
                            {t(label)}
                        </DropdownMenuItem>
                    ))}
                </DropdownMenuGroup>
            </DropdownMenu>
        </DropdownMenuTrigger>
    );
}
