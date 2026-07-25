import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';

/**
 * Horizontal separator between alternate auth methods (password / passkey / 2FA).
 */
export default function OrDivider({
    className,
    label,
}: {
    className?: string;
    /** Override the default translated "or" label. */
    label?: string;
}) {
    const { t } = useTranslation();

    return (
        <div
            className={cn(
                'relative flex items-center justify-center',
                className,
            )}
        >
            <div
                className="absolute inset-0 flex items-center"
                aria-hidden="true"
            >
                <div className="w-full border-t border-border" />
            </div>
            <span className="relative bg-background px-2 text-xs text-muted-foreground uppercase">
                {label ?? t('or')}
            </span>
        </div>
    );
}
