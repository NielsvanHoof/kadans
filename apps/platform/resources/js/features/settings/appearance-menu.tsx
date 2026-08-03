import { Monitor, Moon, Sun } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import type { Appearance } from '@/hooks/use-appearance';
import { useAppearance } from '@/hooks/use-appearance';
import { useTranslation } from '@/hooks/use-translation';

/**
 * Compact header appearance control (Light / Dark / System).
 * Trigger icon follows the resolved theme so the control reads at a glance.
 */
export default function AppearanceMenu() {
    const { appearance, resolvedAppearance, updateAppearance } =
        useAppearance();
    const { t } = useTranslation();

    const TriggerIcon = resolvedAppearance === 'dark' ? Moon : Sun;

    return (
        <DropdownMenuTrigger>
            <Button
                variant="ghost"
                size="icon"
                className="size-9"
                aria-label={t('Appearance')}
            >
                <TriggerIcon className="size-5" aria-hidden="true" />
            </Button>
            <DropdownMenu
                placement="bottom end"
                className="min-w-40"
                selectionMode="single"
                selectedKeys={[appearance]}
                onSelectionChange={(keys) => {
                    const [next] = Array.from(keys);
                    if (next !== undefined) {
                        ((next) => updateAppearance(next as Appearance))(
                            String(next),
                        );
                    }
                }}
            >
                <DropdownMenuGroup>
                    <DropdownMenuLabel>{t('Appearance')}</DropdownMenuLabel>

                    <DropdownMenuItem id="light">
                        <Sun aria-hidden="true" />
                        {t('Light')}
                    </DropdownMenuItem>
                    <DropdownMenuItem id="dark">
                        <Moon aria-hidden="true" />
                        {t('Dark')}
                    </DropdownMenuItem>
                    <DropdownMenuItem id="system">
                        <Monitor aria-hidden="true" />
                        {t('System')}
                    </DropdownMenuItem>
                </DropdownMenuGroup>
            </DropdownMenu>
        </DropdownMenuTrigger>
    );
}
