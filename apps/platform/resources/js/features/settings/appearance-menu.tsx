import { Monitor, Moon, Sun } from 'lucide-react';
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
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button
                    variant="ghost"
                    size="icon"
                    className="size-9"
                    aria-label={t('Appearance')}
                >
                    <TriggerIcon className="size-5" aria-hidden="true" />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="min-w-40">
                <DropdownMenuGroup>
                    <DropdownMenuLabel>{t('Appearance')}</DropdownMenuLabel>
                    <DropdownMenuRadioGroup
                        value={appearance}
                        onValueChange={(next) =>
                            updateAppearance(next as Appearance)
                        }
                    >
                        <DropdownMenuRadioItem value="light">
                            <Sun aria-hidden="true" />
                            {t('Light')}
                        </DropdownMenuRadioItem>
                        <DropdownMenuRadioItem value="dark">
                            <Moon aria-hidden="true" />
                            {t('Dark')}
                        </DropdownMenuRadioItem>
                        <DropdownMenuRadioItem value="system">
                            <Monitor aria-hidden="true" />
                            {t('System')}
                        </DropdownMenuRadioItem>
                    </DropdownMenuRadioGroup>
                </DropdownMenuGroup>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
