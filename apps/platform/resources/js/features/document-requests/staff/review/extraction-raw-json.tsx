import { Copy, Eye, EyeOff, ShieldAlert } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { useCopyToClipboard } from 'usehooks-ts';
import EmptyState from '@/components/primitives/empty-state';
import { Button } from '@/components/ui/button';
import { formatRawJson } from '@/features/document-requests/staff/review/document-extraction-helpers';
import { useTranslation } from '@/hooks/use-translation';

/** How long the button shows "Copied" before reverting. */
const COPIED_FEEDBACK_MS = 2000;

/**
 * The unprocessed OCR payload.
 *
 * Kept behind a deliberate reveal: unlike the Fields view, this dump contains
 * BSN, IBAN, and other sensitive values in the clear, so it should not appear
 * just because someone opened the tab.
 */
export default function ExtractionRawJson({
    rawJson,
}: {
    rawJson: string | null;
}) {
    const { t } = useTranslation();
    const [, copy] = useCopyToClipboard();
    const [isRevealed, setIsRevealed] = useState(false);
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

    if (rawJson === null || rawJson === '') {
        return <EmptyState title={t('No raw data is available.')} />;
    }

    const copyRawJson = async () => {
        await copy(formatRawJson(rawJson));
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
        <section className="space-y-3 pt-2">
            <p className="text-muted-foreground flex items-start gap-2 text-xs">
                <ShieldAlert className="mt-0.5 size-4 shrink-0" />
                <span>
                    {t(
                        'This shows sensitive client data in full, including BSN and IBAN if present.',
                    )}
                </span>
            </p>

            <div className="flex flex-wrap items-center gap-2">
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    onClick={() => setIsRevealed(!isRevealed)}
                >
                    {isRevealed ? (
                        <EyeOff className="size-3.5" />
                    ) : (
                        <Eye className="size-3.5" />
                    )}
                    {isRevealed ? t('Hide full data') : t('Show full data')}
                </Button>
                {isRevealed && (
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        onClick={copyRawJson}
                        aria-live="polite"
                    >
                        <Copy className="size-3.5" />
                        {justCopied ? t('Copied') : t('Copy')}
                    </Button>
                )}
            </div>

            {isRevealed && (
                <pre className="bg-muted/40 max-h-96 overflow-auto rounded-md border px-3 py-2 font-mono text-xs whitespace-pre-wrap">
                    {formatRawJson(rawJson)}
                </pre>
            )}
        </section>
    );
}
