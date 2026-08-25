import { Mail, TriangleAlert } from 'lucide-react';
import { Button } from '@/components/ui/button';
import DossierReminderComposeDialog from '@/features/dossiers/follow-up/dossier-reminder-compose-dialog';
import { useDossierCapabilities } from '@/features/dossiers/permissions/dossier-permissions-context';
import { validAccessGrants } from '@/features/dossiers/show/dossier-panel';
import type { Dossier } from '@/features/dossiers/types';
import { useFormatters } from '@/hooks/use-formatters';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';

/**
 * What the client can do right now, and the one action that changes it.
 *
 * Portal grants last seven days, so "the client never uploaded" is very often
 * "the link died" — a fact that otherwise lives two tabs away while the list of
 * things it blocks sits right here. Stating link validity beside the schedule
 * turns a support call into a glance.
 *
 * Sending a reminder mints a fresh grant as well as mailing it, so it is the
 * correct action whether the link is alive or dead; the panel-switch is the
 * fallback for staff without reminder rights.
 */
export default function WaitingChaseHeader({
    dossier,
    openCount,
    onSelectAccess,
}: {
    dossier: Dossier;
    openCount: number;
    onSelectAccess: () => void;
}) {
    const { t } = useTranslation();
    const { formatDate } = useFormatters();
    const { canSendReminder } = useDossierCapabilities();

    const activeGrant = validAccessGrants(dossier.access_grants)[0] ?? null;
    const hasEverSentLink = dossier.access_grants.length > 0;
    const canRemind = canSendReminder && dossier.has_outstanding_client_items;

    return (
        <div className="flex flex-col gap-3 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
            <div className="min-w-0 space-y-1">
                <p className="text-sm font-medium">
                    {openCount === 1
                        ? t('1 item open')
                        : t(':count items open', { count: openCount })}
                    <span className="font-normal text-muted-foreground">
                        {' · '}
                        {dossier.last_client_message_sent_at
                            ? t('last asked :date', {
                                  date: formatDate(
                                      dossier.last_client_message_sent_at,
                                  ),
                              })
                            : t('never asked')}
                    </span>
                </p>

                {/*
                 * Amber is this theme's "waiting on someone" hue, and it carries
                 * an icon as well: no state here is signalled by colour alone.
                 */}
                <p
                    className={cn(
                        'flex items-center gap-1.5 text-xs',
                        activeGrant
                            ? 'text-muted-foreground'
                            : 'font-medium text-warning-foreground',
                    )}
                >
                    {activeGrant ? null : (
                        <TriangleAlert
                            className="size-3.5 shrink-0"
                            aria-hidden
                        />
                    )}
                    {activeGrant
                        ? t('Portal link expires :date', {
                              date: formatDate(activeGrant.expires_at),
                          })
                        : hasEverSentLink
                          ? t(
                                'No active portal link — the client cannot upload.',
                            )
                          : t('No portal link has been sent yet.')}
                </p>
            </div>

            <div className="shrink-0">
                {canRemind ? (
                    <DossierReminderComposeDialog dossierId={dossier.id} />
                ) : (
                    <Button
                        type="button"
                        variant="outline"
                        onClick={onSelectAccess}
                    >
                        <Mail aria-hidden />
                        {hasEverSentLink
                            ? t('Open access')
                            : t('Invite client')}
                    </Button>
                )}
            </div>
        </div>
    );
}
