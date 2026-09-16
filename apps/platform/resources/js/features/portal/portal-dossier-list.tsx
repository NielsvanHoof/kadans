import { Link } from '@inertiajs/react';
import { ArrowRight, CheckCircle2, Clock3 } from 'lucide-react';
import type { PortalDossierSummary } from '@/features/portal/types';
import { useFormatters } from '@/hooks/use-formatters';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';
import { show as portalDossierShow } from '@/routes/portal/dossiers';

/**
 * The dossiers behind one client link.
 *
 * Leads with how many items are still needed rather than the dossier's status.
 * A client does not think in `awaiting_client` / `in_review`; they think in
 * "what do you still need from me", and the answer has to survive being read on
 * a phone in a hurry.
 */
export default function PortalDossierList({
    dossiers,
    ariaLabel,
}: {
    dossiers: PortalDossierSummary[];
    ariaLabel: string;
}) {
    const { t } = useTranslation();
    const { formatDate } = useFormatters();

    if (dossiers.length === 0) {
        return null;
    }

    return (
        <ul aria-label={ariaLabel} className="grid gap-3">
            {dossiers.map((dossier) => {
                const isWaiting = dossier.remaining > 0;

                return (
                    <li key={dossier.id}>
                        <Link
                            href={portalDossierShow.url(dossier.id)}
                            className={cn(
                                'group border-border/70 bg-card flex items-center gap-4 rounded-2xl border px-4 py-4 shadow-xs transition',
                                'hover:border-border focus-visible:ring-ring hover:shadow-sm focus-visible:ring-2 focus-visible:outline-none',
                                'md:px-5 md:py-5',
                            )}
                        >
                            <span
                                className={cn(
                                    'flex size-9 shrink-0 items-center justify-center rounded-full',
                                    isWaiting
                                        ? 'bg-warning-muted text-warning-foreground'
                                        : 'bg-success-muted text-success-foreground',
                                )}
                                aria-hidden="true"
                            >
                                {isWaiting ? (
                                    <Clock3 className="size-5" />
                                ) : (
                                    <CheckCircle2 className="size-5" />
                                )}
                            </span>

                            <span className="min-w-0 flex-1 space-y-1">
                                <span className="block text-base font-medium text-pretty">
                                    {dossier.title}
                                </span>

                                <span className="text-muted-foreground block text-sm text-pretty">
                                    {isWaiting
                                        ? t(':count still needed', {
                                              count: dossier.remaining,
                                          })
                                        : t('Nothing needed from you')}
                                    {dossier.due_date
                                        ? ` · ${t('Due :date', {
                                              date: formatDate(
                                                  dossier.due_date,
                                              ),
                                          })}`
                                        : ''}
                                    {dossier.reference
                                        ? ` · ${t('Ref. :reference', {
                                              reference: dossier.reference,
                                          })}`
                                        : ''}
                                </span>
                            </span>

                            <ArrowRight
                                className="text-muted-foreground size-5 shrink-0 transition group-hover:translate-x-0.5"
                                aria-hidden="true"
                            />
                        </Link>
                    </li>
                );
            })}
        </ul>
    );
}
