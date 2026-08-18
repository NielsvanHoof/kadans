import type { AuditMarkVariant } from '@/components/brand/audit-mark';
import StatusBadge from '@/components/brand/status-badge';
import { documentRequestStatusLabel } from '@/features/document-requests/status/document-request-status';
import type { DocumentRequestStatus } from '@/features/document-requests/types';
import { useTranslation } from '@/hooks/use-translation';

/**
 * Each status gets its own margin mark, the way an accountant annotates a
 * checklist. Shape carries the meaning, so the column still reads when the
 * colours are stripped out.
 */
const STATUS_MARKS: Record<DocumentRequestStatus, AuditMarkVariant> = {
    pending: 'open',
    submitted: 'flagged',
    accepted: 'ticked',
    rejected: 'queried',
};

const STATUS_CLASSES: Record<DocumentRequestStatus, string> = {
    pending: 'border-border bg-muted/50 text-muted-foreground',
    submitted: 'border-warning-border bg-warning-muted text-warning-foreground',
    accepted: 'border-success-border bg-success-muted text-success-foreground',
    rejected: 'border-destructive/30 bg-destructive/5 text-destructive',
};

/**
 * The one place a document request status is drawn.
 *
 * Staff review and the client portal both render this, so an item that reads
 * "Approved" with a tick to a reviewer reads exactly the same to the client.
 */
export default function DocumentRequestStatusBadge({
    status,
    className,
}: {
    status: DocumentRequestStatus;
    className?: string;
}) {
    const { t } = useTranslation();

    return (
        <StatusBadge
            mark={STATUS_MARKS[status]}
            statusClassName={STATUS_CLASSES[status]}
            label={documentRequestStatusLabel(status, t)}
            className={className}
        />
    );
}
