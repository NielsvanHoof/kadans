import type { AuditMarkVariant } from '@/components/brand/audit-mark';
import StatusBadge from '@/components/primitives/status-badge';
import { dossierStatusLabel } from '@/features/dossiers/status/dossier-status';
import type { DossierStatus } from '@/features/dossiers/types';
import { useTranslation } from '@/hooks/use-translation';

/**
 * The same four margin marks used on document requests, so a dossier and the
 * items inside it speak one visual language.
 */
const STATUS_MARKS: Record<DossierStatus, AuditMarkVariant> = {
    draft: 'open',
    awaiting_client: 'flagged',
    in_review: 'flagged',
    completed: 'ticked',
};

/**
 * Brass means one thing across the whole interface: waiting on someone else.
 * `awaiting_client` is the only dossier state that qualifies, which is exactly
 * the state a firm scans the list for. `in_review` is also unfinished but it
 * is on your own desk, so it stays neutral.
 */
const STATUS_CLASSES: Record<DossierStatus, string> = {
    draft: 'border-border bg-muted/50 text-muted-foreground',
    awaiting_client:
        'border-warning-border bg-warning-muted text-warning-foreground',
    in_review: 'border-border bg-secondary text-secondary-foreground',
    completed: 'border-success-border bg-success-muted text-success-foreground',
};

export default function DossierStatusBadge({
    status,
    className,
}: {
    status: DossierStatus;
    className?: string;
}) {
    const { t } = useTranslation();

    return (
        <StatusBadge
            mark={STATUS_MARKS[status]}
            statusClassName={STATUS_CLASSES[status]}
            label={dossierStatusLabel(status, t)}
            className={className}
        />
    );
}
