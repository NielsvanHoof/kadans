import type { PropsWithChildren } from 'react';
import Heading from '@/components/primitives/heading';
import { LinkButton } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { useTranslation } from '@/hooks/use-translation';
import { useAdminSections } from '@/layouts/admin/admin-sections';
import { cn, toUrl } from '@/lib/utils';

/**
 * Firm administration chrome. Same rail as account settings, but the content
 * column is deliberately not width-clamped: the members table, the roles matrix
 * and the data-subject request table are all wide. `min-w-0` (never
 * `overflow-x-hidden`) keeps their own `overflow-x-auto` scrollers reachable.
 */
export default function AdminLayout({ children }: PropsWithChildren) {
    const { isCurrentOrParentUrl } = useCurrentUrl();
    const sections = useAdminSections();
    const { t } = useTranslation();

    if (sections.length === 0) {
        return <>{children}</>;
    }

    return (
        <div className="mx-auto w-full p-4 md:p-8">
            <Heading
                level={1}
                title={t('Admin')}
                description={t("Manage your firm's setup and access.")}
            />

            <div className="flex flex-col lg:flex-row lg:space-x-12">
                <aside className="w-full max-w-xl lg:w-56 lg:shrink-0">
                    <nav
                        className="flex flex-col space-y-1 space-x-0"
                        aria-label={t('Admin')}
                    >
                        {sections.map((item) => (
                            <LinkButton
                                key={toUrl(item.href)}
                                size="sm"
                                variant="ghost"
                                className={cn('w-full justify-start', {
                                    'bg-muted': isCurrentOrParentUrl(item.href),
                                })}
                                href={toUrl(item.href)}
                            >
                                {item.title}
                            </LinkButton>
                        ))}
                    </nav>
                </aside>

                <Separator className="my-6 lg:hidden" />

                <div className="min-w-0 flex-1">{children}</div>
            </div>
        </div>
    );
}
