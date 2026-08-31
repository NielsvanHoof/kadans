import { Separator } from '@/components/ui/separator';
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
            <Separator
                className="absolute inset-x-0 top-1/2"
                aria-hidden="true"
            />
            <span className="bg-background text-muted-foreground relative px-2 text-xs uppercase">
                {label ?? t('or')}
            </span>
        </div>
    );
}
