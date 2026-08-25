import { KeyRound } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { useCopyToClipboard } from 'usehooks-ts';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';

/** How long the button shows "Copied" before reverting. */
const COPIED_FEEDBACK_MS = 2000;

/**
 * One-time flash of the client portal URL after creating an access grant.
 * The plain token cannot be retrieved again after this page load.
 *
 * An inline alert rather than its own card: it appears at the top of the Access
 * panel for one page load, and a card would read as permanent furniture.
 */
export default function PortalLinkBanner({
    portalUrl,
    token = null,
}: {
    portalUrl: string;
    token?: string | null;
}) {
    const { t } = useTranslation();
    const [, copy] = useCopyToClipboard();
    // Local flag so the label resets after a short delay (clipboard lib keeps the value).
    const [justCopied, setJustCopied] = useState(false);
    const resetTimeoutRef = useRef<ReturnType<typeof setTimeout> | null>(null);

    useEffect(() => {
        return () => {
            if (resetTimeoutRef.current !== null) {
                clearTimeout(resetTimeoutRef.current);
            }
        };
    }, []);

    const copyPortalLink = async () => {
        await copy(portalUrl);
        setJustCopied(true);

        if (resetTimeoutRef.current !== null) {
            clearTimeout(resetTimeoutRef.current);
        }

        resetTimeoutRef.current = setTimeout(() => {
            setJustCopied(false);
            resetTimeoutRef.current = null;
        }, COPIED_FEEDBACK_MS);
    };

    return (
        <Alert className="border-brand-border bg-brand-muted p-4">
            <KeyRound aria-hidden="true" />
            <AlertTitle className="text-sm">
                {t('Client portal link')}
            </AlertTitle>
            <AlertDescription className="gap-3">
                <p>
                    {t(
                        'Copy this link now. The plain token is shown once and cannot be retrieved again.',
                    )}
                </p>
                <code className="block max-w-full overflow-x-auto rounded-md border border-brand-border/60 bg-card px-3 py-2 font-data text-xs break-all">
                    {portalUrl}
                </code>
                <div className="flex flex-wrap items-center gap-3">
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={copyPortalLink}
                        aria-live="polite"
                    >
                        {justCopied ? t('Copied') : t('Copy portal link')}
                    </Button>
                    {token && (
                        <span className="font-data text-xs text-muted-foreground">
                            {t('Token: :token…', { token: token.slice(0, 8) })}
                        </span>
                    )}
                </div>
            </AlertDescription>
        </Alert>
    );
}
