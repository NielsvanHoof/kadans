import { Head } from '@inertiajs/react';
import { CheckCircle2 } from 'lucide-react';
import PortalDossierList from '@/features/portal/portal-dossier-list';
import PortalSessionNotice from '@/features/portal/portal-session-notice';
import type {
    PortalClient,
    PortalDossierSummary,
    PortalFirm,
    PortalSession,
} from '@/features/portal/types';
import { useTranslation } from '@/hooks/use-translation';
import PortalShell from '@/layouts/portal/portal-shell';

export default function PortalIndex({
    firm,
    client,
    session,
    waiting,
    completed,
}: {
    firm: PortalFirm;
    client: PortalClient;
    session: PortalSession;
    waiting: PortalDossierSummary[];
    completed: PortalDossierSummary[];
}) {
    const { t } = useTranslation();
    const hasAnything = waiting.length > 0 || completed.length > 0;

    return (
        <>
            <Head title={`${t('Your documents')} · ${firm.name}`} />

            <PortalShell
                firmName={firm.name}
                title={t('Your documents')}
                subtitle={t('For :name', { name: client.name })}
            >
                <PortalSessionNotice idleExpiresAt={session.idle_expires_at} />

                {hasAnything ? null : (
                    <section className="border-border/70 bg-card rounded-2xl border px-5 py-8 text-center shadow-xs">
                        <span
                            className="bg-success-muted text-success-foreground mx-auto flex size-11 items-center justify-center rounded-full"
                            aria-hidden="true"
                        >
                            <CheckCircle2 className="size-6" />
                        </span>
                        <h2 className="mt-4 text-lg font-medium">
                            {t('You are all caught up')}
                        </h2>
                        <p className="text-muted-foreground mx-auto mt-2 max-w-md text-base text-pretty">
                            {t(
                                'Nothing is waiting for you right now. :firm will email you when something needs your attention.',
                                { firm: firm.name },
                            )}
                        </p>
                    </section>
                )}

                {waiting.length > 0 && (
                    <section className="space-y-3">
                        <h2 className="text-base font-medium">
                            {t('Waiting for you')}
                        </h2>
                        <PortalDossierList
                            dossiers={waiting}
                            ariaLabel={t('Waiting for you')}
                        />
                    </section>
                )}

                {completed.length > 0 && (
                    <section className="space-y-3">
                        <h2 className="text-base font-medium">
                            {t('Nothing outstanding')}
                        </h2>
                        <PortalDossierList
                            dossiers={completed}
                            ariaLabel={t('Nothing outstanding')}
                        />
                    </section>
                )}

                <div className="text-muted-foreground space-y-1 text-center text-sm text-pretty">
                    <p>
                        {t(
                            'This is a private link for :firm. Keep it confidential.',
                            { firm: firm.name },
                        )}
                    </p>
                    <p>
                        {t('Questions? Contact :name at :email.', {
                            name: firm.contact_name,
                            email: firm.contact_email,
                        })}
                    </p>
                </div>
            </PortalShell>
        </>
    );
}
