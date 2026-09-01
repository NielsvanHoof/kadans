import { KeyRound } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { useCopyToClipboard } from 'usehooks-ts';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';

const COPIED_FEEDBACK_MS = 2000;

/**
 * One-time flash of the client portal URL after creating a portal link.
 */
export default function PortalLinkBanner({ portalUrl }: { portalUrl: string }) {
    const { t } = useTranslation();
    const [, copy] = useCopyToClipboard();
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
                        "Copy this link now. It's shown only once and can't be viewed again.",
                    )}
                </p>
                <code className="border-brand-border/60 bg-card font-data block max-w-full overflow-x-auto rounded-md border px-3 py-2 text-xs break-all">
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
                </div>
            </AlertDescription>
        </Alert>
    );
}
