import { Form } from '@inertiajs/react';
import { Clock } from 'lucide-react';
import ClientPortalSessionExtendController from '@/actions/App/ClientPortal/Http/Controllers/ClientPortalSessionExtendController';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { usePortalSessionCountdown } from '@/features/portal/hooks/use-portal-session-countdown';
import { useTranslation } from '@/hooks/use-translation';

/**
 * Warns the client before their secure session ends, and lets them keep it.
 *
 * The portal used to end sessions silently, so the first sign was a click that
 * failed. Warning is the honest fix: the session still ends, but never by
 * surprise, and a client who is still at their desk can say so.
 *
 * `aria-live="polite"` rather than `assertive` — this interrupts nobody
 * mid-sentence, it just needs to be read out once it appears.
 */
export default function PortalSessionNotice({
    idleExpiresAt,
}: {
    idleExpiresAt: string;
}) {
    const { t } = useTranslation();
    const { secondsRemaining, isExpiringSoon, hasEnded } =
        usePortalSessionCountdown(idleExpiresAt);

    if (!isExpiringSoon && !hasEnded) {
        return null;
    }

    return (
        <div aria-live="polite">
            <Alert variant={hasEnded ? 'destructive' : 'default'}>
                <Clock aria-hidden />
                <AlertTitle>
                    {hasEnded
                        ? t('Your secure session has ended')
                        : t('Your secure session is about to end')}
                </AlertTitle>
                <AlertDescription className="space-y-2">
                    <p>
                        {hasEnded
                            ? t(
                                  'Everything you already submitted is saved. Open the link from your email again to carry on.',
                              )
                            : t(
                                  'It ends in about :minutes. Everything you already submitted is saved.',
                                  {
                                      minutes: formatMinutesRemaining(
                                          secondsRemaining,
                                          t,
                                      ),
                                  },
                              )}
                    </p>
                    {hasEnded ? null : (
                        <Form
                            {...ClientPortalSessionExtendController.form()}
                            // `session` specifically: the whole point of the
                            // round trip is to read back the deadline the
                            // middleware just slid forward.
                            options={{
                                preserveScroll: true,
                                only: ['session', 'flash'],
                            }}
                        >
                            {({ processing }) => (
                                <Button
                                    type="submit"
                                    size="sm"
                                    variant="outline"
                                    isDisabled={processing}
                                >
                                    {t('Keep my session open')}
                                </Button>
                            )}
                        </Form>
                    )}
                </AlertDescription>
            </Alert>
        </div>
    );
}

/**
 * Rounds up, so "1 minute" never means "any moment now" — a client told
 * "0 minutes" learns nothing they can act on.
 */
function formatMinutesRemaining(
    secondsRemaining: number,
    t: (key: string, replacements?: Record<string, string | number>) => string,
): string {
    if (secondsRemaining < 60) {
        return t('less than a minute');
    }

    return t(':count minutes', { count: Math.ceil(secondsRemaining / 60) });
}
