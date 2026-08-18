import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';

type SkipToMainContentProps = {
    className?: string;
};

/**
 * First tab stop on staff and portal shells. Hidden until focused so
 * keyboard users can jump past chrome without a permanent visual control.
 */
export default function SkipToMainContent({
    className,
}: SkipToMainContentProps) {
    const { t } = useTranslation();

    return (
        <a
            href="#main-content"
            className={cn(
                // Visually hidden until focused; then a solid, on-screen control.
                'sr-only focus:not-sr-only focus:absolute focus:top-4 focus:left-4 focus:z-100 focus:rounded-md focus:bg-background focus:px-4 focus:py-2 focus:text-sm focus:font-medium focus:text-foreground focus:ring-2 focus:ring-ring focus:outline-none',
                className,
            )}
        >
            {t('Skip to content')}
        </a>
    );
}
