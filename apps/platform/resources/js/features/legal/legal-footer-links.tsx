import { Link } from '@inertiajs/react';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';
import { show as legalShow } from '@/routes/legal';

type LegalFooterLinksProps = {
    className?: string;
};

/**
 * Shared privacy / cookies / terms links for auth, portal, and legal layouts.
 */
export default function LegalFooterLinks({ className }: LegalFooterLinksProps) {
    const { t } = useTranslation();

    const links = [
        { slug: 'privacy', label: t('Privacy') },
        { slug: 'cookies', label: t('Cookies') },
        { slug: 'terms', label: t('Terms') },
    ] as const;

    return (
        <nav
            aria-label={t('Legal')}
            className={cn(
                'flex flex-wrap items-center justify-center gap-x-4 gap-y-2 text-xs text-muted-foreground',
                className,
            )}
        >
            {links.map((link) => (
                <Link
                    key={link.slug}
                    href={legalShow.url(link.slug)}
                    className="hover:text-foreground"
                >
                    {link.label}
                </Link>
            ))}
        </nav>
    );
}
