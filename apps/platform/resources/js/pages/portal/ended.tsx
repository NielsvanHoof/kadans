import { Head } from '@inertiajs/react';
import { Clock, ShieldOff } from 'lucide-react';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { useTranslation } from '@/hooks/use-translation';
import PortalShell from '@/layouts/portal/portal-shell';

type PortalSessionEndReason = App.ClientPortal.Enums.PortalSessionEndReason;

/**
 * Explains a portal session that stopped working.
 *
 * Clients used to land on the app-wide error page here, which — because they
 * are not signed in and never will be — offered them a "Go to login" button.
 * This page answers the only two questions they actually have: is my work gone,
 * and what do I do now.
 */
export default function PortalEnded({
    reason,
    firm_name,
}: {
    reason: PortalSessionEndReason;
    firm_name: string | null;
}) {
    const { t } = useTranslation();

    const isTimedOut = reason === 'timed_out';

    const title = isTimedOut
        ? t('Your secure session has ended')
        : t('This link is no longer active');

    return (
        <>
            <Head title={title} />

            <PortalShell firmName={firm_name} title={title}>
                <Alert variant={isTimedOut ? 'default' : 'destructive'}>
                    {isTimedOut ? (
                        <Clock aria-hidden />
                    ) : (
                        <ShieldOff aria-hidden />
                    )}
                    <AlertTitle>
                        {isTimedOut
                            ? t('Everything you submitted is saved')
                            : t('Access to this dossier has ended')}
                    </AlertTitle>
                    <AlertDescription>
                        {isTimedOut
                            ? t(
                                  'Secure sessions close after a period of inactivity. Nothing you sent has been lost.',
                              )
                            : t(
                                  'The secure link for this dossier was withdrawn or has expired, so it can no longer be opened.',
                              )}
                    </AlertDescription>
                </Alert>

                <div className="rounded-xl border border-border/70 bg-card px-4 py-3.5 text-base text-pretty shadow-xs">
                    <p className="font-medium">{t('What to do next')}</p>
                    <p className="mt-1 text-muted-foreground">
                        {isTimedOut
                            ? t(
                                  'Open the link from your email again to carry on where you left off.',
                              )
                            : firm_name
                              ? t(
                                    'Contact :firm if you still need to send something. They can send you a new link.',
                                    { firm: firm_name },
                                )
                              : t(
                                    'Contact the firm that invited you if you still need to send something. They can send you a new link.',
                                )}
                    </p>
                </div>

                {/*
                    No link back into the portal on purpose: the plaintext token
                    only ever exists in the client's email, so there is nothing
                    here we could honestly point them at.
                */}
            </PortalShell>
        </>
    );
}
