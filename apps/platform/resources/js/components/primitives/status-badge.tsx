import type { ReactNode } from 'react';
import AuditMark, {
    type AuditMarkVariant,
} from '@/components/brand/audit-mark';
import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';

type StatusBadgeProps = {
    mark: AuditMarkVariant;
    label: ReactNode;
    /** Status-specific colour classes; mark shape carries meaning without them. */
    statusClassName: string;
    className?: string;
};

/**
 * Outlined status chip with an audit mark.
 * Shape (open / flagged / ticked / queried) is the non-colour cue; colour is secondary.
 */
export default function StatusBadge({
    mark,
    label,
    statusClassName,
    className,
}: StatusBadgeProps) {
    return (
        <Badge
            variant="outline"
            className={cn('shrink-0 gap-1.5', statusClassName, className)}
        >
            {/* `inherit` so the mark takes the badge colour, not a second palette. */}
            <AuditMark variant={mark} tone="inherit" />
            {label}
        </Badge>
    );
}
