import type { AuditMarkVariant } from '@/components/brand/audit-mark';
import StatusBadge from '@/components/primitives/status-badge';
import { pipelineStatusLabel } from '@/features/pipelines/status/pipeline-status';
import type { PipelineStatus } from '@/features/pipelines/types';
import { useTranslation } from '@/hooks/use-translation';

/**
 * Pipeline runs use the same margin marks as dossiers and document requests
 * so shape (not fill colour) carries meaning in the status column.
 */
const STATUS_MARKS: Record<PipelineStatus, AuditMarkVariant> = {
    pending: 'open',
    running: 'flagged',
    completed: 'ticked',
    failed: 'queried',
    stuck: 'queried',
    cancelled: 'open',
};

const STATUS_CLASSES: Record<PipelineStatus, string> = {
    pending: 'border-border bg-muted/50 text-muted-foreground',
    running: 'border-border bg-secondary text-secondary-foreground',
    completed: 'border-success-border bg-success-muted text-success-foreground',
    failed: 'border-destructive/30 bg-destructive/5 text-destructive',
    stuck: 'border-destructive/30 bg-destructive/5 text-destructive',
    cancelled: 'border-border bg-muted/50 text-muted-foreground',
};

export default function PipelineStatusBadge({
    status,
    className,
}: {
    status: PipelineStatus;
    className?: string;
}) {
    const { t } = useTranslation();

    return (
        <StatusBadge
            mark={STATUS_MARKS[status]}
            statusClassName={STATUS_CLASSES[status]}
            label={pipelineStatusLabel(status, t)}
            className={className}
        />
    );
}
