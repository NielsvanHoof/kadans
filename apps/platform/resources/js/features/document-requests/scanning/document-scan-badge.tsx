import { Badge } from '@/components/ui/badge';
import type { DocumentScanState } from '@/features/document-requests/types';
import { useTranslation } from '@/hooks/use-translation';

/**
 * English keys for the security-check states. Pass through `t()` for display.
 */
export const DOCUMENT_SCAN_STATE_LABELS: Record<DocumentScanState, string> = {
    scanning: 'Running security check',
    ready: 'Ready',
    blocked: 'Blocked by security check',
};

export function documentScanStateLabel(
    scanState: DocumentScanState,
    t: (key: string) => string,
): string {
    return t(DOCUMENT_SCAN_STATE_LABELS[scanState]);
}

/**
 * The security check's state, for someone deciding whether to open a file.
 *
 * Only three states reach here, and that is deliberate: the check distinguishes
 * a detection from a file it could not read from an outright failure, and none
 * of that is the reader's problem — all three mean the file will not open, so
 * all three say so. The detail is in the audit log.
 *
 * Renders nothing when the file is ready. A badge on every healthy document
 * would be noise on a page that already carries a review status and a
 * processing status.
 */
export default function DocumentScanBadge({
    scanState,
}: {
    scanState: DocumentScanState;
}) {
    const { t } = useTranslation();

    if (scanState === 'ready') {
        return null;
    }

    return (
        <Badge variant={scanState === 'blocked' ? 'destructive' : 'secondary'}>
            {documentScanStateLabel(scanState, t)}
        </Badge>
    );
}
