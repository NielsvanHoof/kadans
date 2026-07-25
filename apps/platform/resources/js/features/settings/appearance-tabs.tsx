import { Monitor, Moon, Sun } from 'lucide-react';
import SegmentedControl from '@/components/segmented-control';
import type { Appearance } from '@/hooks/use-appearance';
import { useAppearance } from '@/hooks/use-appearance';
import { useTranslation } from '@/hooks/use-translation';

/**
 * Appearance preference segmented control (Light / Dark / System).
 */
export default function AppearanceTabs({
    className = '',
}: {
    className?: string;
}) {
    const { appearance, updateAppearance } = useAppearance();
    const { t } = useTranslation();

    return (
        <SegmentedControl
            aria-label={t('Appearance')}
            className={className}
            value={appearance}
            onValueChange={(next) => updateAppearance(next as Appearance)}
            options={[
                {
                    value: 'light',
                    label: t('Light'),
                    icon: <Sun className="size-4" aria-hidden="true" />,
                },
                {
                    value: 'dark',
                    label: t('Dark'),
                    icon: <Moon className="size-4" aria-hidden="true" />,
                },
                {
                    value: 'system',
                    label: t('System'),
                    icon: <Monitor className="size-4" aria-hidden="true" />,
                },
            ]}
        />
    );
}
