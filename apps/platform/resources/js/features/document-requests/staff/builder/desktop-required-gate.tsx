import { Link } from '@inertiajs/react';
import { Monitor } from 'lucide-react';
import EmptyState from '@/components/primitives/empty-state';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';

/**
 * Honest desktop-only gate for the form builder.
 * Phones and narrow tablets cannot author the three-pane canvas.
 */
export default function DesktopRequiredGate({
    backHref,
}: {
    backHref: string;
}) {
    const { t } = useTranslation();

    return (
        <div className="flex min-h-0 flex-1 items-center justify-center p-2">
            <EmptyState
                variant="panel"
                icon={Monitor}
                title={t('Form builder needs a larger screen')}
                description={t(
                    'Building a client form uses a side-by-side palette, canvas, and settings panel. Open this page on a desktop or laptop to continue.',
                )}
                action={
                    <Button asChild>
                        <Link href={backHref}>{t('Back to dossier')}</Link>
                    </Button>
                }
            />
        </div>
    );
}
