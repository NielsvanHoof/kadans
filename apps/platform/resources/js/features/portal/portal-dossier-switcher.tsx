import { Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import type { PortalDossierSummary } from '@/features/portal/types';
import { useTranslation } from '@/hooks/use-translation';
import { show as portalDossierShow } from '@/routes/portal/dossiers';
import { show as portalShow } from '@/routes/portal';

/**
 * Move between the dossiers one client link opens.
 *
 * Plain links, not a listbox that navigates on selection: this has to render
 * server-side with no browser, and a select that moves you the moment focus
 * changes is a trap for anyone arrowing through it with a keyboard or a screen
 * reader. Renders nothing at all for a link that opens a single dossier, which
 * is what keeps the one-dossier page exactly as it was.
 */
export default function PortalDossierSwitcher({
    otherDossiers,
}: {
    otherDossiers: PortalDossierSummary[];
}) {
    const { t } = useTranslation();

    if (otherDossiers.length === 0) {
        return null;
    }

    return (
        <nav
            aria-label={t('Your other documents')}
            className="border-border/70 bg-card space-y-3 rounded-xl border px-4 py-3 shadow-xs"
        >
            <Link
                href={portalShow.url()}
                className="text-muted-foreground hover:text-foreground focus-visible:ring-ring inline-flex items-center gap-2 rounded-sm text-sm focus-visible:ring-2 focus-visible:outline-none"
            >
                <ArrowLeft className="size-4" aria-hidden="true" />
                {t('All documents')}
            </Link>

            <ul className="flex flex-wrap gap-2">
                {otherDossiers.map((dossier) => (
                    <li key={dossier.id}>
                        <Link
                            href={portalDossierShow.url(dossier.id)}
                            className="border-border/70 bg-secondary text-secondary-foreground hover:border-border focus-visible:ring-ring inline-flex items-center gap-2 rounded-full border px-3 py-1.5 text-sm transition focus-visible:ring-2 focus-visible:outline-none"
                        >
                            <span className="max-w-[16rem] truncate">
                                {dossier.title}
                            </span>
                            {dossier.remaining > 0 ? (
                                <span className="bg-warning-muted text-warning-foreground rounded-full px-2 py-0.5 text-xs font-medium">
                                    {dossier.remaining}
                                </span>
                            ) : null}
                        </Link>
                    </li>
                ))}
            </ul>
        </nav>
    );
}
