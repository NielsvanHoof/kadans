import { Head } from '@inertiajs/react';
import StatusMessage from '@/components/primitives/status-message';
import PortalDocumentRequestsCard from '@/features/document-requests/portal/portal-document-requests-card';
import PortalProgressOverview from '@/features/portal/portal-progress-overview';
import PortalSessionNotice from '@/features/portal/portal-session-notice';
import type {
    PortalDossier,
    PortalFirm,
    PortalSession,
} from '@/features/portal/types';
import { useFormatters } from '@/hooks/use-formatters';
import { useTranslation } from '@/hooks/use-translation';
import PortalShell from '@/layouts/portal/portal-shell';

export default function PortalShow({
    firm,
    dossier,
    session,
}: {
    firm: PortalFirm;
    dossier: PortalDossier;
    session: PortalSession;
}) {
    const { t } = useTranslation();
    const { formatDateTime } = useFormatters();

    const subtitle = [
        t('For :name', { name: dossier.client.name }),
        dossier.reference
            ? t('Ref. :reference', { reference: dossier.reference })
            : null,
    ]
        .filter(Boolean)
        .join(' · ');

    const meta = dossier.expires_at
        ? t('Access expires :date', {
              date: formatDateTime(dossier.expires_at),
          })
        : undefined;

    return (
        <>
            <Head title={`${dossier.title} · ${firm.name}`} />

            <PortalShell
                firmName={firm.name}
                title={dossier.title}
                subtitle={subtitle}
                meta={meta}
            >
                <PortalSessionNotice idleExpiresAt={session.idle_expires_at} />

                {dossier.status === 'completed' && (
                    <StatusMessage>
                        {t(
                            'This dossier has been completed. Your submitted information is now read-only.',
                        )}
                    </StatusMessage>
                )}

                {dossier.status !== 'completed' ? (
                    <p className="border-border/70 bg-card text-muted-foreground rounded-xl border px-4 py-3 text-base text-pretty shadow-xs">
                        {t(
                            'Your answers are saved as you go. You can leave and come back using the same link.',
                        )}
                    </p>
                ) : null}

                <PortalProgressOverview
                    dossier={dossier}
                    firmName={firm.name}
                />

                <PortalDocumentRequestsCard
                    firmName={firm.name}
                    documentRequests={dossier.document_requests}
                    nextIncompleteRequestId={
                        dossier.progress.next_incomplete?.id ?? null
                    }
                />

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
